ALTER TABLE users
  ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user',
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0;

-- Optional: promote an existing user to admin and activate.
-- UPDATE users SET role = 'admin', is_active = 1 WHERE useremail = 'admin@example.com';
