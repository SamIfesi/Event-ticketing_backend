<?php

// Generate signed  upload params -- aby logged-in user
$router->post('/api/cloudinary/sign', [CloudinaryController::class, 'sign'], [AuthMiddleware::class]);

// SAve avatar after successful Cloudinary upload 
$router->post('/api/cloudinary/avatar', [CloudinaryController::class, 'saveAvatar'], [AuthMiddleware::class]);

// SAve event banner after successful Cloudinary upload 
$router->post('/api/cloudinary/banner/id', [CloudinaryController::class, 'saveBanner'], [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'admin', 'dev']]);
