<?php
class CSRFAuth
{
    function csrfInit(): void
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    function csrfToken(): string
    {
        $this->csrfInit();
        return (string)$_SESSION['csrf'];
    }

    function csrfVerifyPostOrRedirect(string $page): void
    {
        $token = $_POST['csrf'] ?? '';
        
        if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            $err = rawurlencode('Invalid request (CSRF)');
            $redir = '/?page=' . rawurlencode($page) . '&error=' . $err;
            header('Location: ' . $redir);
            exit;
        }
    }

    function csrfValidate(): bool
    {
        $this->csrfInit();
        $token = $_POST['csrf'] ?? '';

        return empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token);
    }
}
