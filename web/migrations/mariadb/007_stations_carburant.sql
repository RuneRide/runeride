CREATE TABLE fuel_stations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country CHAR(2) NOT NULL,
    station_id VARCHAR(64) NOT NULL,
    brand VARCHAR(190) NULL,
    name VARCHAR(190) NULL,
    address VARCHAR(255) NOT NULL DEFAULT '',
    city VARCHAR(190) NOT NULL DEFAULT '',
    post_code VARCHAR(16) NOT NULL DEFAULT '',
    latitude DOUBLE NOT NULL,
    longitude DOUBLE NOT NULL,
    currency CHAR(3) NOT NULL,
    prices_json TEXT NOT NULL,
    station_updated_at DATETIME NULL,
    cached_at DATETIME NOT NULL,
    UNIQUE KEY uq_fuel_country_station (country, station_id),
    INDEX idx_fuel_geo (country, latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
