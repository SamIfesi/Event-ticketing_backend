<?php

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

// load all the classes
require_once __DIR__ . '/config/Environment.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';

require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Router.php';

require_once __DIR__ . '/services/JWTService.php';

require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/middleware/RoleMiddleware.php';
require_once __DIR__ . '/middleware/DevMiddleware.php';

// Controllers — add each one here as you build them
// require_once __DIR__ . '/controllers/AuthController.php';
// require_once __DIR__ . '/controllers/EventController.php';
// require_once __DIR__ . '/controllers/BookingController.php';
// require_once __DIR__ . '/controllers/TicketController.php';
// require_once __DIR__ . '/controllers/CategoryController.php';
// require_once __DIR__ . '/controllers/AdminController.php';
// require_once __DIR__ . '/controllers/DevController.php';


// Load .env variables into $_ENV
Environment::load(__DIR__ . '/.env');

// Create the request object from the incoming HTTP request
$request = new Request();

// Create the router
$router = new Router();

// ============================================================
// 4. REGISTER ROUTES
//    Uncomment each file as you build the controllers
// ============================================================
// require_once __DIR__ . '/routes/auth.php';
// require_once __DIR__ . '/routes/events.php';
// require_once __DIR__ . '/routes/bookings.php';
// require_once __DIR__ . '/routes/tickets.php';
// require_once __DIR__ . '/routes/admin.php';
// require_once __DIR__ . '/routes/dev.php';

// ============================================================
// 5. HEALTH CHECK — test this first to confirm the API works
// ============================================================
$router->get('/api/health', [HealthController::class, 'check']);

// Temporary inline health check (remove once controllers exist)
if ($request->uri === '/api/health' && $request->method === 'GET') {
  Response::success([
    'app'     => 'Event Ticketing API',
    'status'  => 'running',
    'env'     => Environment::get('APP_ENV', 'development'),
  ], 'API is healthy');
}

// ============================================================
// 6. DISPATCH — match the request to a route and run it
// ============================================================
$router->dispatch($request);
