<?php

declare(strict_types=1);

// ============================================================
// 1. CORS
// ============================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

// ============================================================
// 2. AUTOLOAD
// ============================================================

require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';

require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Router.php';

require_once __DIR__ . '/helpers/ValidationHelper.php';
require_once __DIR__ . '/helpers/TokenHelper.php';

require_once __DIR__ . '/services/JWTService.php';
require_once __DIR__ . '/services/PaystackService.php';
require_once __DIR__ . '/services/QRCodeService.php';
require_once __DIR__ . '/services/MailService.php';
require_once __DIR__ . '/services/LogService.php';
require_once __DIR__ . '/services/QueueService.php';

require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/middleware/RoleMiddleware.php';
require_once __DIR__ . '/middleware/DevMiddleware.php';

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ProfileController.php';
require_once __DIR__ . '/controllers/EventController.php';
require_once __DIR__ . '/controllers/BookingController.php';
require_once __DIR__ . '/controllers/TicketController.php';
require_once __DIR__ . '/controllers/CategoryController.php';
require_once __DIR__ . '/controllers/AdminController.php';
require_once __DIR__ . '/controllers/DevController.php';

// ============================================================
// 3. BOOTSTRAP
// ============================================================
$request = new Request();
$router  = new Router();

// ============================================================
// 4. HEALTH CHECK
// Must be registered BEFORE routes so it matches first
// Works regardless of subfolder — matches end of URI
// ============================================================
if (str_ends_with($request->uri, '/api/health') && $request->method === 'GET') {
  Response::success([
    'app'     => 'Event Ticketing API',
    'status'  => 'running',
    'env'     => Environment::get('APP_ENV', 'development'),
    'php'     => PHP_VERSION,
  ], 'API is healthy');
}

// ============================================================
// 5. REGISTER ROUTES
// ============================================================
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/profile.php';
require_once __DIR__ . '/routes/events.php';
require_once __DIR__ . '/routes/bookings.php';
require_once __DIR__ . '/routes/tickets.php';
require_once __DIR__ . '/routes/categories.php';
require_once __DIR__ . '/routes/admin.php';
require_once __DIR__ . '/routes/dev.php';

// ============================================================
// 6. DISPATCH
// ============================================================
$router->dispatch($request);
