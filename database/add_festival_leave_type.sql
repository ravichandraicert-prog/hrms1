-- Add Festival Leave type if it doesn't exist
INSERT INTO `leave_types` (`name`, `calculation_basis`, `accrual_method`, `max_days_per_year`, `min_days_per_request`, `carry_forward`, `max_carry_forward_days`, `applicable_to`, `is_paid`, `requires_approval`, `description`, `is_active`, `assign_to`, `target_id`)
SELECT 'Festival Leave', 'working_days', 'fixed_annual', 0, 1.0, 0, 0, 'all', 1, 1, 'Leave for festival holidays as per assigned holiday calendar', 1, 'all_employees', NULL
WHERE NOT EXISTS (SELECT 1 FROM `leave_types` WHERE `name` = 'Festival Leave');
