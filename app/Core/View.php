<?php
declare(strict_types=1);
namespace App\Core;
final class View
{
    public static function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require dirname(__DIR__) . '/Views/' . $template . '.php';
    }

    /** Render a trusted internal view partial inside the shared workspace shell. */
    public static function renderWithWorkspaceLayout(string $template, array $data = []): void
    {
        if (str_contains($template, '..') || !preg_match('/^[a-zA-Z0-9_\/-]+$/D', $template)) {
            throw new \InvalidArgumentException('Invalid internal view name.');
        }
        $viewPath = dirname(__DIR__) . '/Views/' . $template . '.php';
        if (!is_file($viewPath)) throw new \RuntimeException('Internal view was not found.');

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $viewPath;
            $contentHtml = (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        require dirname(__DIR__) . '/Views/layouts/workspace.php';
    }
}
