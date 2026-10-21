<?php

namespace App\Domain\Forms;

use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Forms\Models\TrainingRegistration;
use App\Domain\Integrations\IntegrationEvents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Заявки форм (W-09, §5.1): контактная форма и регистрация на обучение. Запись и событие outbox — в одной транзакции;
 * письмо на info@aiqme.com уходит после commit (IntegrationEvents), его сбой заявку не отменяет. Клиенту писем нет.
 * Вложения — непрозрачные приватные файлы (§6.4): имя — UUID на диске local (storage/app/private), исходное имя и sha256 —
 * метаданные; содержимое не разбирается. При ошибке записи заявки сохранённые файлы удаляются.
 */
final class FormSubmissions
{
    /** Каталог вложений контактной формы на диске local. */
    public const ATTACHMENTS_DIRECTORY = 'contact-requests';

    public function __construct(private IntegrationEvents $events) {}

    /**
     * @param  array{name: string, company?: ?string, email: string, phone?: ?string, service: string, description: string, deadline?: ?string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function submitContact(array $data, array $files, string $locale, string $serviceTitle): ContactRequest
    {
        $attachments = [];
        try {
            foreach ($files as $file) {
                $path = $file->storeAs(self::ATTACHMENTS_DIRECTORY, (string) Str::uuid(), 'local');
                if ($path === false) {
                    throw new \RuntimeException('Attachment was not stored.');
                }
                $attachments[] = [
                    'path' => $path,
                    'name' => Str::limit($file->getClientOriginalName(), 200, ''),
                    'size' => $file->getSize(),
                    'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
                ];
            }

            return DB::transaction(function () use ($data, $attachments, $locale, $serviceTitle): ContactRequest {
                $request = ContactRequest::create([...$data, 'locale' => $locale, 'attachments' => $attachments]);
                $this->events->record('contact_request.created', $request, [
                    'kind' => 'contact',
                    'name' => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'company' => $request->company,
                    'subject' => $serviceTitle,
                    'summary' => Str::limit(Str::squish($request->description), 200),
                    'attachments' => count($attachments),
                    'amount' => null,
                    'admin_url' => route('lt.admin', ['request' => $request->id]),
                ]);

                return $request;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_column($attachments, 'path'));
            throw $exception;
        }
    }

    /** @param  array{name: string, email: string, format: string, participants: int}  $data */
    public function registerTraining(array $data, ContentTranslation $training, string $locale): TrainingRegistration
    {
        return DB::transaction(function () use ($data, $training, $locale): TrainingRegistration {
            $registration = TrainingRegistration::create([
                ...$data,
                'locale' => $locale,
                'content_item_id' => $training->item->id,
                'training_title' => $training->title,
                'training_date' => $training->item->meta('date'),
            ]);
            $this->events->record('training_registration.created', $registration, [
                'kind' => 'training',
                'name' => $registration->name,
                'email' => $registration->email,
                'phone' => null,
                'company' => null,
                'subject' => trim($registration->training_title.' · '.$registration->training_date, ' ·'),
                'summary' => Str::limit($registration->training_title, 200),
                'format' => $registration->format,
                'participants' => $registration->participants,
                'attachments' => 0,
                'amount' => null,
                'admin_url' => route('lt.admin.training').'#training-registrations',
            ]);

            return $registration;
        });
    }
}
