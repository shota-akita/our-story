CREATE DATABASE date_memories
    DEFAULT CHARACTER SET utf8mb4;
USE date_memories;

CREATE TABLE memories (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    date        DATE NOT NULL,
    photo_url   TEXT,
    album_url   TEXT,
    description TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE locations (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    memory_id BIGINT,
    name      VARCHAR(255) NOT NULL,
    FOREIGN KEY (memory_id) REFERENCES memories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE DATABASE auth_system
    DEFAULT CHARACTER SET utf8mb4;
USE auth_system;

CREATE TABLE login_users (
    username        VARCHAR(50)  PRIMARY KEY,
    password        VARCHAR(255) NOT NULL,
    redirect_target VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
