-- Remove ON DELETE CASCADE from leave_type_id foreign key in leave_balances
-- This allows leave types to be deleted while preserving balance records is not needed
-- (we want to delete balances when the leave type is deleted)

-- No changes needed - leave_balances should be deleted when leave type is deleted
-- This is the correct behavior
