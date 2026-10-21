<?php

namespace App\Domain\Integrations\Telegram;

use RuntimeException;

/** Ошибка Bot API или сети; сообщение уже без токена бота (TelegramClient). */
final class TelegramException extends RuntimeException {}
