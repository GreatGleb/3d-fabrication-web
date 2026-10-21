<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Domain\Forms\FormSubmissions;
use App\Domain\Forms\Models\TrainingRegistration;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Отправка контактной формы и регистрации на обучение (W-09): проверка ввода (поля форм прототипа, §6.4 для вложений),
 * honeypot (скрытое поле website_hp: заполнено — ошибка формы без записи, ложного успеха нет) и частота по IP —
 * middleware throttle:forms. Запись, событие и письмо — FormSubmissions. Успех — сообщение на странице формы.
 */
class FormController extends Controller
{
    public const HONEYPOT = 'website_hp';

    public function __construct(private readonly PublishedContent $content, private readonly FormSubmissions $forms) {}

    public function contact(Request $request): RedirectResponse
    {
        $locale = app()->getLocale();
        $this->rejectHoneypot($request);
        // Значение выбора — slug услуги текущей локали (как в ссылках предзаполнения §1.2) или equipment.
        $services = $this->content->list(ContentType::Service, $locale)->keyBy('slug');
        $limits = config('aiqme.forms');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'service' => ['required', 'string', Rule::in([...$services->keys(), 'equipment'])],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'deadline' => ['nullable', 'date'],
            'attachments' => ['nullable', 'array', 'max:'.$limits['attachments_max_files']],
            'attachments.*' => ['file', 'max:'.$limits['attachments_max_kb'], 'extensions:'.implode(',', $limits['attachments_extensions']), $this->signature(...)],
        ], $this->messages([
            'attachments.*.uploaded' => __('public.forms.file_size', ['max' => intdiv($limits['attachments_max_kb'], 1024)]),
            'attachments.max' => __('public.forms.files_count'),
            'attachments.*.max' => __('public.forms.file_size', ['max' => intdiv($limits['attachments_max_kb'], 1024)]),
            'attachments.*.extensions' => __('public.forms.file_type', ['types' => implode(', ', $limits['attachments_extensions'])]),
        ]));

        $service = $services->get($data['service']);
        $files = array_values($request->file('attachments', []));
        $this->forms->submitContact(
            [...collect($data)->except('attachments')->all(), 'service' => $service?->item->key ?? 'equipment'],
            $files,
            $locale,
            $service?->title ?? __('public.contact.equipment'),
        );

        return redirect()->route($locale.'.contact')->with('contact_sent', count($files));
    }

    public function training(Request $request): RedirectResponse
    {
        $locale = app()->getLocale();
        $this->rejectHoneypot($request);
        $data = $request->validate([
            'training' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'format' => ['required', Rule::in(TrainingRegistration::FORMATS)],
            'participants' => ['required', 'integer', 'min:1', 'max:20'],
        ], $this->messages([
            'participants.min' => __('public.forms.participants', ['min' => 1, 'max' => 20]),
            'participants.max' => __('public.forms.participants', ['min' => 1, 'max' => 20]),
            'participants.integer' => __('public.forms.participants', ['min' => 1, 'max' => 20]),
        ]));

        $training = $this->content->list(ContentType::Training, $locale)
            ->first(fn (ContentTranslation $topic): bool => $topic->item->id === (int) $data['training']);
        if ($training === null) {
            throw ValidationException::withMessages(['form' => __('public.forms.rejected')]);
        }
        $this->forms->registerTraining(collect($data)->except('training')->all(), $training, $locale);

        return redirect()->back(fallback: route($locale.'.training'))->with('training_registered', $training->item->id);
    }

    private function rejectHoneypot(Request $request): void
    {
        if (filled($request->input(self::HONEYPOT))) {
            throw ValidationException::withMessages(['form' => __('public.forms.rejected')]);
        }
    }

    /** PDF, JPEG и PNG — по сигнатуре (§6.4); STL, STEP и OBJ не разбираются. */
    private function signature(string $attribute, mixed $file, Closure $fail): void
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return;
        }
        $signatures = ['pdf' => '%PDF-', 'jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'png' => "\x89PNG\r\n\x1A\n"];
        $expected = $signatures[strtolower($file->getClientOriginalExtension())] ?? null;
        if ($expected !== null && (string) file_get_contents($file->getRealPath(), length: strlen($expected)) !== $expected) {
            $fail(__('public.forms.file_content'));
        }
    }

    /**
     * Сообщения проверки LT/EN по имени правила (Q-19-04) и уточнения по полям.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function messages(array $fields): array
    {
        return [
            'required' => __('public.forms.required'),
            'email' => __('public.forms.email'),
            'max' => __('public.forms.max'),
            'min' => __('public.forms.min_text'),
            'in' => __('public.forms.choice'),
            'integer' => __('public.forms.choice'),
            'date' => __('public.forms.date'),
            'file' => __('public.forms.file_content'),
            ...$fields,
        ];
    }
}
