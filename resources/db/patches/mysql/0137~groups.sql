INSERT IGNORE INTO groups (name, description) VALUES ('alt.binaries.fz', 'This group contains german Movies and TV.') ON DUPLICATE KEY UPDATE name = 'alt.binaries.fz';
