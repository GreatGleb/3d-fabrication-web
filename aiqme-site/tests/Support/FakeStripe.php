<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Stripe\ErrorObject;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\SearchResult;
use Stripe\StripeClientInterface;
use Stripe\Util\RequestOptions;

/**
 * Мок Stripe на границе SDK (§5.5, уровень L): реализует StripeClientInterface, поэтому настоящие PaymentIntentService
 * SDK 16.6.0 строят пути и параметры как с сетью, а запросы сюда не уходят. Хранит intent'ы, повторяет создание по
 * ключу идемпотентности как Stripe, поддерживает retrieve, cancel и search. На каждый вызов запоминает
 * DB::transactionLevel() — тест сравнивает его с уровнем на начало теста (с RefreshDatabase — 1).
 * $fail — очередь отказов: следующий вызов операции бросает исключение SDK (таймаут, 4xx, «уже оплачен»).
 */
final class FakeStripe implements StripeClientInterface
{
    /** @var list<array{operation: string, params: array<string, mixed>, opts: array<string, mixed>, level: int}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> intent по id */
    public array $intents = [];

    /** @var array<string, string> id intent по ключу идемпотентности */
    private array $keys = [];

    /** @var array<string, list<Closure(): ApiErrorException>> операция => очередь отказов */
    private array $failures = [];

    /** @var array<string, true> Search API отстаёт: созданный intent виден поиску только после index() или put() */
    private array $searchable = [];

    /** Вызывается после создания intent до ответа — «параллельное» действие между транзакциями 1 и 2. */
    public ?Closure $afterCreate = null;

    /** Следующий вызов операции (create, retrieve, cancel, search) бросает исключение. */
    public function fail(string $operation, Closure $exception): self
    {
        $this->failures[$operation][] = $exception;

        return $this;
    }

    public static function timeout(): Closure
    {
        return fn (): ApiErrorException => ApiConnectionException::factory('Request timed out (fake).');
    }

    public static function badRequest(): Closure
    {
        return fn (): ApiErrorException => InvalidRequestException::factory('Invalid request (fake).', 400, null, null, null, 'parameter_invalid');
    }

    /** Ответ Stripe при отмене оплаченного или уже отменённого intent. */
    public static function unexpectedState(): Closure
    {
        return fn (): ApiErrorException => InvalidRequestException::factory('This PaymentIntent cannot be canceled (fake).', 400, null, null, null, ErrorObject::CODE_PAYMENT_INTENT_UNEXPECTED_STATE);
    }

    /**
     * Intent «существует в Stripe» (например, создан запросом, ответ на который потерян) и виден поиску.
     *
     * @param  array<string, mixed>  $fields
     */
    public function put(string $id, array $fields): void
    {
        $this->intents[$id] = ['id' => $id, 'object' => 'payment_intent', 'livemode' => false, ...($this->intents[$id] ?? []), ...$fields];
        $this->searchable[$id] = true;
    }

    /** Покупатель оплатил: succeeded, деньги получены, заряд latest_charge. */
    public function pay(string $id, ?int $received = null): void
    {
        $this->put($id, ['status' => 'succeeded', 'amount_received' => $received ?? $this->intents[$id]['amount'], 'latest_charge' => 'ch_fake_'.$id]);
    }

    /** @return list<string> операции по порядку */
    public function operations(): array
    {
        return array_column($this->calls, 'operation');
    }

    /** @return list<int> уровни транзакции БД при вызовах */
    public function levels(): array
    {
        return array_column($this->calls, 'level');
    }

    public function request($method, $path, $params, $opts)
    {
        $params ??= [];
        $opts = RequestOptions::parse($opts);
        $operation = match (true) {
            $method === 'post' && $path === '/v1/payment_intents' => 'create',
            $method === 'get' && preg_match('#^/v1/payment_intents/([^/]+)$#', $path) === 1 => 'retrieve',
            $method === 'post' && preg_match('#^/v1/payment_intents/([^/]+)/cancel$#', $path) === 1 => 'cancel',
            default => throw new \LogicException("FakeStripe: unexpected {$method} {$path}"),
        };
        $this->record($operation, $params, $opts);
        $id = explode('/', $path)[3] ?? null;

        return match ($operation) {
            'create' => $this->create($params, $opts->headers['Idempotency-Key'] ?? null),
            'retrieve' => $this->intent($id),
            'cancel' => $this->cancel($id),
        };
    }

    public function requestSearchResult($method, $path, $params, $opts)
    {
        $this->record('search', $params ?? [], RequestOptions::parse($opts));
        preg_match("/metadata\\['request_id'\\]:'([^']+)'/", $params['query'] ?? '', $match);
        $found = array_values(array_filter($this->intents, fn (array $intent): bool => isset($this->searchable[$intent['id']])
            && ($intent['metadata']['request_id'] ?? null) === ($match[1] ?? false)));

        return SearchResult::constructFrom(['object' => 'search_result', 'data' => $found, 'has_more' => false]);
    }

    public function getApiKey()
    {
        return 'test-stripe-secret-not-real';
    }

    public function getClientId()
    {
        return null;
    }

    public function getApiBase()
    {
        return 'https://api.stripe.invalid';
    }

    public function getConnectBase()
    {
        return 'https://connect.stripe.invalid';
    }

    public function getFilesBase()
    {
        return 'https://files.stripe.invalid';
    }

    public function getMeterEventsBase()
    {
        return 'https://meter-events.stripe.invalid';
    }

    private function record(string $operation, array $params, RequestOptions $opts): void
    {
        $this->calls[] = ['operation' => $operation, 'params' => $params, 'opts' => $opts->headers, 'level' => DB::transactionLevel()];
        if (($this->failures[$operation] ?? []) !== []) {
            throw array_shift($this->failures[$operation])();
        }
    }

    private function create(array $params, ?string $key): PaymentIntent
    {
        if ($key !== null && isset($this->keys[$key])) {
            return $this->intent($this->keys[$key]);
        }
        $id = 'pi_fake_'.(count($this->intents) + 1);
        $this->intents[$id] = [
            'id' => $id,
            'object' => 'payment_intent',
            'livemode' => false,
            'amount' => $params['amount'],
            'amount_received' => 0,
            'currency' => $params['currency'],
            'status' => 'requires_payment_method',
            'client_secret' => $id.'_secret_fake',
            'latest_charge' => null,
            'metadata' => $params['metadata'],
        ];
        if ($key !== null) {
            $this->keys[$key] = $id;
        }
        if ($this->afterCreate !== null) {
            ($this->afterCreate)($id);
        }

        return $this->intent($id);
    }

    private function cancel(string $id): PaymentIntent
    {
        if (in_array($this->intents[$id]['status'], ['succeeded', 'canceled'], true)) {
            throw self::unexpectedState()();
        }
        $this->intents[$id]['status'] = 'canceled';

        return $this->intent($id);
    }

    private function intent(string $id): PaymentIntent
    {
        return PaymentIntent::constructFrom($this->intents[$id] ?? throw InvalidRequestException::factory('No such payment_intent (fake).', 404, null, null, null, 'resource_missing'));
    }

    /** Созданный intent становится виден поиску (Search API отстаёт). */
    public function index(string $id): void
    {
        $this->searchable[$id] = true;
    }
}
