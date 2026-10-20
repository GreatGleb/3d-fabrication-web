<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Запросы «из отдельного браузера» в Feature-тестах (W-07): каждый запрос несёт только cookie своей сессии, а охранник
 * входа и хранилище сессий сбрасываются между запросами — вход не переживает запрос через объекты приложения, только
 * через cookie и строку сессии в БД (драйвер database).
 */
trait BrowserSession
{
    protected function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $session  id сессии браузера (null — новый браузер); обновляется из Set-Cookie ответа
     */
    protected function browse(string $method, string $uri, array $data = [], ?string &$session = null): TestResponse
    {
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        // Охранник входа, redirect()->with…() и assertSession…() берут session.store — тот же объект, что запустит StartSession.
        app()->instance('session.store', $store = app('session')->driver());
        app('redirect')->setSession($store);
        $this->defaultCookies = $session === null ? [] : [config('session.cookie') => $session];

        $response = $method === 'GET' ? $this->get($uri) : $this->call($method, $uri, $data, $this->prepareCookiesForRequest());

        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie !== null) {
            $session = $cookie->getValue();
        }

        return $response;
    }
}
