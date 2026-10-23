-- Copyright (C) 2026 DPG Supply
-- Share links created by Document Browser, so they can expire. Links shared by hand are never recorded here.

CREATE TABLE llx_docbrowser_share(
    rowid           INTEGER       AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER       NOT NULL DEFAULT 1,
    fk_ecm_files    INTEGER       NOT NULL,
    share           VARCHAR(128)  NOT NULL,
    date_last_sent  DATETIME      NOT NULL,
    tms             TIMESTAMP
) ENGINE=innodb;
