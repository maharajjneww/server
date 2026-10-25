-- Seed Roles
INSERT INTO `roles` (`id`, `name`, `description`) VALUES 
(1, 'User', 'Standard mobile app user with view access'),
(2, 'Moderator', 'Market and result moderator'),
(3, 'Administrator', 'Full administrative authority')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Seed Admin User (admin@matkalive.internal / admin123)
INSERT INTO `users` (`id`, `uuid`, `name`, `email`, `phone`, `password_hash`, `role_id`, `status`, `verification_status`) VALUES 
(1, '670c539e-ccb1-11f1-94e7-a02919333a48', 'System Administrator', 'admin@matkalive.internal', NULL, '$2y$10$5fEkchsxxYMvN8mm9Ua2I.N1BlMWCWrjRmEFbDXhp41STnVk9sWhm', 3, 'active', 'verified')
ON DUPLICATE KEY UPDATE `status` = 'active';

-- Seed Test User (test.user@matka.com / password123)
INSERT INTO `users` (`id`, `uuid`, `name`, `email`, `phone`, `password_hash`, `role_id`, `status`, `verification_status`) VALUES 
(2, '06e3eab3-6558-41cb-8beb-3de3592d4641', 'Test User', 'test.user@matka.com', '+919999988888', '$2y$12$mqSqvG.YQSlpCazSl67hHuS1JYkQHa9Zuch35T6spv0T/U3LeqMTy', 1, 'active', 'verified')
ON DUPLICATE KEY UPDATE `status` = 'active';

-- Seed Markets
INSERT INTO `markets` (`id`, `name`, `open_time`, `close_time`, `status`, `is_featured`, `delay_minutes`, `is_active`, `sort_order`) VALUES 
('mkt_live_test', 'KALYAN LIVE (TEST)', '09:00:00', '23:59:00', 'running', 1, 5, 1, 0),
('mkt_kalyan', 'KALYAN', '16:50:00', '18:50:00', 'closed', 1, 5, 1, 1),
('mkt_milan_day', 'MILAN DAY', '15:25:00', '17:25:00', 'closed', 1, 5, 1, 2),
('mkt_rajdhani_day', 'RAJDHANI DAY', '15:15:00', '17:15:00', 'closed', 1, 5, 1, 3),
('mkt_time_bazar', 'TIME BAZAR', '13:20:00', '14:20:00', 'closed', 0, 5, 1, 4),
('mkt_madhur_day', 'MADHUR DAY', '13:30:00', '14:30:00', 'closed', 0, 5, 1, 5),
('mkt_kalyan_night', 'KALYAN NIGHT', '21:45:00', '23:45:00', 'closed', 1, 5, 1, 6),
('mkt_main_bazar', 'MAIN BAZAR', '22:00:00', '00:10:00', 'closed', 1, 5, 1, 7),
('mkt_milan_night', 'MILAN NIGHT', '21:20:00', '23:20:00', 'closed', 0, 5, 1, 8),
('mkt_supreme_night', 'SUPREME NIGHT', '20:45:00', '22:45:00', 'closed', 0, 5, 1, 9),
('mkt_milan_morning', 'MILAN MORNING', '10:30:00', '11:30:00', 'closed', 1, 5, 1, 10),
('mkt_sridevi_morning', 'SRIDEVI MORNING', '10:10:00', '11:10:00', 'closed', 0, 5, 1, 11),
('mkt_main_bazar_morning', 'MAIN BAZAR MORNING', '11:15:00', '12:15:00', 'closed', 1, 5, 1, 12),
('mkt_sridevi', 'SRIDEVI', '11:35:00', '12:35:00', 'closed', 1, 5, 1, 13),
('mkt_madhuri', 'MADHURI', '11:45:00', '12:45:00', 'closed', 0, 5, 1, 14),
('mkt_kalyan_morning', 'KALYAN MORNING', '11:59:00', '12:59:00', 'closed', 1, 5, 1, 15),
('mkt_maharani', 'MAHARANI', '12:15:00', '14:15:00', 'closed', 0, 5, 1, 16),
('mkt_padmavathi', 'PADMAVATHI', '11:40:00', '12:40:00', 'closed', 0, 5, 1, 17),
('mkt_madhur_morning', 'MADHUR MORNING', '11:30:00', '12:30:00', 'closed', 1, 5, 1, 18),
('mkt_new_time_bazar', 'NEW TIME BAZAR', '13:00:00', '14:00:00', 'running', 1, 5, 1, 19),
('mkt_main_sridevi_day', 'MAIN SRIDEVI DAY', '13:05:00', '14:05:00', 'running', 1, 5, 1, 20),
('mkt_sunday_bazar', 'SUNDAY BAZAR', '13:00:00', '15:00:00', 'running', 1, 5, 1, 21),
('mkt_super_day', 'SUPER DAY', '13:10:00', '14:15:00', 'running', 0, 5, 1, 22),
('mkt_prabhat', 'PRABHAT', '13:25:00', '15:05:00', 'running', 0, 5, 1, 23)
ON DUPLICATE KEY UPDATE 
    `open_time` = VALUES(`open_time`),
    `close_time` = VALUES(`close_time`),
    `status` = VALUES(`status`),
    `is_featured` = VALUES(`is_featured`),
    `sort_order` = VALUES(`sort_order`);
