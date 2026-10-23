-- Copyright (C) 2026 DPG Supply

ALTER TABLE llx_docbrowser_share ADD UNIQUE INDEX uk_docbrowser_share_ecm (fk_ecm_files);
ALTER TABLE llx_docbrowser_share ADD INDEX idx_docbrowser_share_date (entity, date_last_sent);
