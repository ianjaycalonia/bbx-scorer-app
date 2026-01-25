-- Migration: Update No Contact Pocket to Fault in match_finishes table
-- This updates the ENUM and converts existing data

-- First, modify the ENUM to include both old and new values temporarily
ALTER TABLE `match_finishes` 
MODIFY COLUMN `finish_type` enum('Spin Finish','Burst Finish','Over Finish','Xtreme Finish','No Contact Pocket','Fault') NOT NULL;

-- Convert existing No Contact Pocket records to Fault
UPDATE `match_finishes` 
SET `finish_type` = 'Fault' 
WHERE `finish_type` = 'No Contact Pocket';

-- Now remove the old No Contact Pocket value from the ENUM
ALTER TABLE `match_finishes` 
MODIFY COLUMN `finish_type` enum('Spin Finish','Burst Finish','Over Finish','Xtreme Finish','Fault') NOT NULL;
