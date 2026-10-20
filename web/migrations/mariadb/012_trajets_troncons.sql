CREATE TABLE trajets_troncons (
    trip_id BIGINT UNSIGNED NOT NULL,
    way_id BIGINT NOT NULL,
    segment SMALLINT UNSIGNED NOT NULL,
    graph_version VARCHAR(64) NOT NULL,
    PRIMARY KEY (trip_id, way_id, segment),
    INDEX idx_troncons_way (way_id, segment),
    CONSTRAINT fk_troncons_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
