-- hookdata schema (inferred from hook.php / view.php).
-- Columns/types reflect how the application binds and queries each table.
-- Adjust types to taste; createDate/updateDate are stored as the strings the
-- caller sends (not validated as SQL datetimes by the app).

CREATE TABLE IF NOT EXISTS register (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(255) NOT NULL,
    firstName     VARCHAR(255) DEFAULT NULL,
    lastName      VARCHAR(255) DEFAULT NULL,
    tel           VARCHAR(64)  NOT NULL,
    accountNumber VARCHAR(64)  NOT NULL,
    bankName      VARCHAR(128) DEFAULT NULL,
    prefix        VARCHAR(64)  DEFAULT NULL,
    createDate    VARCHAR(64)  DEFAULT NULL,
    bonus         VARCHAR(64)  DEFAULT NULL,
    KEY idx_register_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deposit (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(255) NOT NULL,
    bankName   VARCHAR(128) DEFAULT NULL,
    bankNo     VARCHAR(64)  DEFAULT NULL,
    dateBank   VARCHAR(64)  DEFAULT NULL,
    detail     TEXT         DEFAULT NULL,
    value      BIGINT       DEFAULT NULL,
    bonus      BIGINT       DEFAULT NULL,
    topUp      VARCHAR(64)  DEFAULT NULL,
    prefix     VARCHAR(64)  DEFAULT NULL,
    createDate VARCHAR(64)  DEFAULT NULL,
    updateDate VARCHAR(64)  DEFAULT NULL,
    actionName VARCHAR(128) DEFAULT NULL,
    KEY idx_deposit_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS withdraw (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(255) NOT NULL,
    accountNumber VARCHAR(64)  NOT NULL,
    tel           VARCHAR(64)  NOT NULL,
    bankName      VARCHAR(128) DEFAULT NULL,
    name          VARCHAR(255) DEFAULT NULL,
    value         BIGINT       DEFAULT NULL,
    beforeValue   BIGINT       DEFAULT NULL,
    afterValue    VARCHAR(64)  DEFAULT NULL,
    type          VARCHAR(64)  DEFAULT NULL,
    prefix        VARCHAR(64)  DEFAULT NULL,
    createDate    VARCHAR(64)  DEFAULT NULL,
    updateDate    VARCHAR(64)  DEFAULT NULL,
    actionName    VARCHAR(128) DEFAULT NULL,
    KEY idx_withdraw_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
