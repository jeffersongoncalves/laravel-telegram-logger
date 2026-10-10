<?php

use JeffersonGoncalves\TelegramLogger\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/** Fake bot token in Telegram's "<bot id>:<secret>" shape. */
const BOT_TOKEN = '123456789:AAsEcReT-ToKeN_abcdefghijklmnopqrstuv';

const DESTINATION = ['token' => BOT_TOKEN, 'chat_id' => '-1001234567890'];
