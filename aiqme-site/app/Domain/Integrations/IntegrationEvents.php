<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Mail\FormSubmitted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Outbox (§2.7): событие пишется в транзакции изменения, доставка — по каналам. Канал mail — письмо на адрес
 * aiqme.forms.mail_to (W-09); канал sheets создаётся pending и до W-18 не обрабатывается (его не трогают ни задание,
 * ни повтор). Задание канала захватывает его одним UPDATE, отправляет вне транзакции и ставит sent или failed;
 * повтор раз в 5 минут подбирает недоставленное и зависшее (retryMail).
 */
final class IntegrationEvents
{
    /** Через сколько минут недоставленный или зависший в sending канал подбирает повтор. */
    public const RETRY_AFTER_MINUTES = 5;

    /**
     * Записывает событие (вызывать в транзакции изменения) и ставит письмо в очередь после commit. События без письма
     * (§5.1: цена, платёжные запросы, стадии — только Sheets) — $mail = false, канал mail = none.
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

        return $event;
    }

    /**
     * Доставка письма события. Захват — атомарный UPDATE только из pending/failed или зависшего sending; 0 строк —
     * канал уже взят другим заданием или доставлен, выход без письма. Ошибка транспорта — failed с текстом ошибки:
     * заявка остаётся, повтор подберёт канал позже.
     */
    public function deliverMail(int $eventId): void
    {
        $now = Carbon::now();
        $claimed = DB::table('integration_events')
            ->where('id', $eventId)
            ->where(fn ($query) => $query->whereIn('mail_status', ['pending', 'failed'])
                ->orWhere(fn ($query) => $query->where('mail_status', 'sending')->where('mail_claimed_at', '<', $now->copy()->subMinutes(self::RETRY_AFTER_MINUTES))))
            ->update(['mail_status' => 'sending', 'mail_claimed_at' => $now, 'mail_attempts' => DB::raw('mail_attempts + 1'), 'updated_at' => $now]);
        if ($claimed === 0) {
            return;
        }

        $event = IntegrationEvent::findOrFail($eventId);
        try {
            Mail::to(config('aiqme.forms.mail_to'))->send(new FormSubmitted($event));
        } catch (Throwable $exception) {
            report($exception);
            $event->forceFill(['mail_status' => 'failed', 'mail_error' => Str::limit($exception->getMessage(), 1000)])->save();

            return;
        }
        $event->forceFill(['mail_status' => 'sent', 'mail_error' => null])->save();
    }

    /**
     * Повтор канала mail: pending и failed, последняя попытка (или создание) которых старше 5 минут, и sending,
     * захваченные больше 5 минут назад. Каждому — задание; захват в задании не даёт отправить письмо дважды.
     * Канал sheets не затрагивается (до W-18).
     *
     * @return int число поставленных заданий
     */
    public function retryMail(): int
    {
        $ids = IntegrationEvent::query()
            ->whereIn('mail_status', ['pending', 'failed', 'sending'])
            ->whereRaw('coalesce(mail_claimed_at, created_at) < ?', [Carbon::now()->subMinutes(self::RETRY_AFTER_MINUTES)])
            ->orderBy('id')
            ->pluck('id');
        foreach ($ids as $id) {
            DeliverMail::dispatch($id);
        }

        return $ids->count();
    }
}
