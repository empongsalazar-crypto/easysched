<?php
declare(strict_types=1);

const EASYSCHED_CAPTCHA_TTL = 300;

function easysched_captcha_issue(): array
{
    $operator = ['+', '-', 'x'][random_int(0, 2)];
    if ($operator === '+') {
        $left = random_int(2, 12); $right = random_int(1, 9); $answer = $left + $right;
    } elseif ($operator === '-') {
        $left = random_int(5, 15); $right = random_int(1, $left); $answer = $left - $right;
    } else {
        $left = random_int(2, 9); $right = random_int(2, 5); $answer = $left * $right;
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['login_captcha_required'] = true;
    $_SESSION['login_challenge_expression'] = "{$left} {$operator} {$right}";
    $_SESSION['login_challenge_token'] = $token;
    $_SESSION['login_challenge_answer_hash'] = hash('sha256', $token . '|' . $answer);
    $_SESSION['login_challenge_created_at'] = time();
    return ['captcha_required' => true];
}

function easysched_captcha_clear(): void
{
    unset($_SESSION['login_captcha_required'], $_SESSION['login_challenge_expression'], $_SESSION['login_challenge_token'], $_SESSION['login_challenge_answer_hash'], $_SESSION['login_challenge_created_at']);
}

function easysched_captcha_is_available(): bool
{
    return !empty($_SESSION['login_captcha_required'])
        && !empty($_SESSION['login_challenge_expression'])
        && !empty($_SESSION['login_challenge_token'])
        && !empty($_SESSION['login_challenge_answer_hash'])
        && time() - (int) ($_SESSION['login_challenge_created_at'] ?? 0) <= EASYSCHED_CAPTCHA_TTL;
}

function easysched_captcha_validate(string $answer): bool
{
    if (!easysched_captcha_is_available() || !preg_match('/^\d{1,3}$/', trim($answer))) return false;
    $givenHash = hash('sha256', (string) $_SESSION['login_challenge_token'] . '|' . trim($answer));
    $valid = hash_equals((string) $_SESSION['login_challenge_answer_hash'], $givenHash);
    unset($_SESSION['login_challenge_expression'], $_SESSION['login_challenge_token'], $_SESSION['login_challenge_answer_hash'], $_SESSION['login_challenge_created_at']);
    return $valid;
}
