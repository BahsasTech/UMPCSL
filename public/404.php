<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

http_response_code(404);
$user = current_user();
render_header('Page not found', $user);
?>
<div class="text-center py-5">
    <div class="display-1 text-muted">404</div>
    <h1 class="h4">We couldn't find that page</h1>
    <p class="text-muted">The page you are looking for may have been moved or deleted.</p>
    <a href="/" class="btn btn-primary"><i class="bi bi-house"></i> Back to home</a>
</div>
<?php render_footer(); ?>
