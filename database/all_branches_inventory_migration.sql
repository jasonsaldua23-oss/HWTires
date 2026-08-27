-- Enable inventory for every branch.
-- Run once on existing deployments after pulling the branch management update.

ALTER TABLE branches
    MODIFY has_inventory BOOLEAN DEFAULT TRUE;

UPDATE branches
SET has_inventory = 1
WHERE has_inventory <> 1 OR has_inventory IS NULL;
