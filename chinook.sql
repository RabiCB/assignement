CREATE DATABASE IF NOT EXISTS chinook;
USE chinook;

DROP TABLE IF EXISTS tracks;
DROP TABLE IF EXISTS albums;
DROP TABLE IF EXISTS artists;

CREATE TABLE artists (
  ArtistId SMALLINT(6) NOT NULL AUTO_INCREMENT,
  Name VARCHAR(85) NOT NULL,
  PRIMARY KEY (ArtistId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO artists (Name) VALUES
('Coldplay'),
('Adele'),
('Imagine Dragons'),
('Ed Sheeran'),
('The Beatles'),
('Linkin Park'),
('Taylor Swift'),
('Metallica'),
('Billie Eilish'),
('Queen');

CREATE TABLE albums (
  AlbumId SMALLINT(6) NOT NULL AUTO_INCREMENT,
  Title VARCHAR(95) NOT NULL,
  ArtistId SMALLINT(6) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (AlbumId),
  FOREIGN KEY (ArtistId) REFERENCES artists(ArtistId)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO albums (Title, ArtistId) VALUES
('Parachutes', 1),
('25', 2),
('Evolve', 3),
('Divide', 4),
('Abbey Road', 5),
('Hybrid Theory', 6),
('1989', 7),
('Master of Puppets', 8),
('When We All Fall Asleep', 9),
('A Night at the Opera', 10);


CREATE TABLE tracks (
  TrackId INT NOT NULL AUTO_INCREMENT,
  Name VARCHAR(120) NOT NULL,
  AlbumId SMALLINT(6) NOT NULL,
  Duration INT DEFAULT 0, 
  PRIMARY KEY (TrackId),
  FOREIGN KEY (AlbumId) REFERENCES albums(AlbumId)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO tracks (Name, AlbumId, Duration) VALUES
('Yellow', 1, 270),
('Trouble', 1, 245),
('Hello', 2, 295),
('Send My Love', 2, 223),
('Believer', 3, 204),
('Thunder', 3, 187),
('Shape of You', 4, 233),
('Perfect', 4, 263),
('Come Together', 5, 259),
('Something', 5, 183),
('In the End', 6, 216),
('Crawling', 6, 209),
('Blank Space', 7, 231),
('Style', 7, 231),
('Battery', 8, 312),
('Master of Puppets', 8, 515),
('Bad Guy', 9, 194),
('Bury a Friend', 9, 188),
('Bohemian Rhapsody', 10, 355),
('Love of My Life', 10, 217);