-- Local development seed data for our-story.
-- Both demo accounts use the sample password "password".
-- Never load these accounts in a public or production environment.
USE auth_system;

REPLACE INTO login_users (username, password, redirect_target) VALUES
('demo-editor', '$2y$12$i16pSkh4xtFb4xB0LmI0Ru0mniedHItMd0Xfe/hJP9iUoZK2iLEo6', 'index.php'),
('demo-viewer', '$2y$12$i16pSkh4xtFb4xB0LmI0Ru0mniedHItMd0Xfe/hJP9iUoZK2iLEo6', 'index2.php');
