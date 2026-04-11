USE event_ticketing;

CREATE TABLE jobs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type         VARCHAR(100)  NOT NULL,          -- e.g. 'send_otp', 'send_ticket_confirmation'
    payload      JSON          NOT NULL,           -- all data the job needs to run
    status       ENUM('pending', 'processing', 'done', 'failed')
                 NOT NULL DEFAULT 'pending',
    attempts     TINYINT       NOT NULL DEFAULT 0, -- how many times it has been tried
    max_attempts TINYINT       NOT NULL DEFAULT 3, -- give up after 3 tries
    error        TEXT          DEFAULT NULL,       -- last error message if failed
    available_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME      DEFAULT NULL
);

-- Index for the worker to quickly find pending jobs
CREATE INDEX idx_jobs_status_available ON jobs (status, available_at);
