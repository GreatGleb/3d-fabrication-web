<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Integrations\Sheets\ProjectSheets;
use App\Domain\Integrations\Sheets\SheetsProjector;
use App\Mail\FormSubmitted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Outbox (§2.7): событие пишется в транзакции изменения, доставка — по каналам, у каждого свой статус, попытки и ошибка.
 * Канал mail — письмо на адрес aiqme.forms.mail_to (W-09, очередь mail); канал sheets — проекция в Google Таблицу
 * (W-18, очередь sheets). Задание канала захватывает его одним UPDATE, отправляет вне транзакции и ставит sent или
 * failed; повтор раз в 5 минут подбирает недоставленное и зависшее (retry). Сбой одного канала не трогает другой.
 */
final class IntegrationEvents
{
    /** Через сколько минут недоставленный или зависший в sending канал подбирает повтор. */
    public const RETRY_AFTER_MINUTES = 5;

    /**
     * Записывает событие (вызывать в транзакции изменения) и после commit ставит задания каналов: письмо и проекцию
     * в Sheets. События без письма (§5.1: цена, платёжные запросы, стадии — только Sheets) — $mail = false, канал
     * mail = none.
     *
     * @param  array<string, mixed>  $data  данные письма и листа Events (summary, amount)
     */
    public function record(string $type, Model $entity, array $data, bool $mail = true): IntegrationEvent
    {
        $event = IntegrationEvent::create([
            'type' => $type,
            'entity_type' => $entity->getTable(),
            'entity_id' => $entity->getKey(),
            'data' => $data,
            'mail_status' => $mail ? 'pending' : 'none',
            'sheets_status' => 'pending',
        ]);
        if ($mail) {
            DeliverMail::dispatch($event->id)->afterCommit();
        }
        ProjectSheets::dispatch($event->id, SheetsProjector::entityKey($event))->afterCommit();

        return $event;
    }

    /**
     * Доставка письма события. Захват — атомарный UPDATE только из pending/failed или зависшего sending; 0 строк —
     * канал уже взят другим заданием или доставлен, выход без письма. Ошибка транспорта — failed с текстом ошибки:
     * заявка остаётся, повтор подберёт канал позже.
     */
    public function deliverMail(int $eventId): void
    {
        $this->deliver($eventId, 'mail', fn (IntegrationEvent $event) => Mail::to(config('aiqme.forms.mail_to'))->send(new FormSubmitted($event)));
    }

    /**
     * Проекция события в Google Таблицу (SheetsProjector перечитывает состояние из БД). Без ключа и ID таблицы канал не
     * захватывается: событие остаётся pending и доставляется, когда Sheets подключат.
     */
    public function deliverSheets(int $eventId): void
    {
        if (! SheetsProjector::configured()) {
            return;
        }
        $this->deliver($eventId, 'sheets', fn (IntegrationEvent $event) => app(SheetsProjector::class)->project($event));
    }

    /**
     * Повтор каналов: pending и failed, последняя попытка (или создание) которых старше 5 минут, и sending, захваченные
     * больше 5 минут назад. Каждому — задание своего канала; захват в задании не даёт доставить дважды. Канал sheets без
     * подключения не повторяется.
     *
     * @return int число поставленных заданий
     */
    public function retry(): int
    {
        $count = 0;
        foreach (SheetsProjector::configured() ? ['mail', 'sheets'] : ['mail'] as $channel) {
            $events = IntegrationEvent::query()
                ->whereIn($channel.'_status', ['pending', 'failed', 'sending'])
                ->whereRaw("coalesce({$channel}_claimed_at, created_at) < ?", [Carbon::now()->subMinutes(self::RETRY_AFTER_MINUTES)])
                ->orderBy('id')
                ->get(['id', 'entity_type', 'entity_id']);
            foreach ($events as $event) {
                $channel === 'mail' ? DeliverMail::dispatch($event->id) : ProjectSheets::dispatch($event->id, SheetsProjector::entityKey($event));
            }
            $count += $events->count();
        }

        return $count;
    }

    /** Захват канала, доставка вне транзакции и итог sent или failed (с текстом ошибки). */
    private function deliver(int $eventId, string $channel, callable $send): void
    {
        $now = Carbon::now();
        $claimed = DB::table('integration_events')
            ->where('id', $eventId)
            ->where(fn ($query) => $query->whereIn($channel.'_status', ['pending', 'failed'])
                ->orWhere(fn ($query) => $query->where($channel.'_status', 'sending')->where($channel.'_claimed_at', '<', $now->copy()->subMinutes(self::RETRY_AFTER_MINUTES))))
            ->update([$channel.'_status' => 'sending', $channel.'_claimed_at' => $now, $channel.'_attempts' => DB::raw($channel.'_attempts + 1'), 'updated_at' => $now]);
        if ($claimed === 0) {
            return;
        }

        $event = IntegrationEvent::findOrFail($eventId);
        try {
            $send($event);
        } catch (Throwable $exception) {
            report($exception);
            $event->forceFill([$channel.'_status' => 'failed', $channel.'_error' => Str::limit($exception->getMessage(), 1000)])->save();

            return;
        }
        $event->forceFill([$channel.'_status' => 'sent', $channel.'_error' => null])->save();
    }
}
