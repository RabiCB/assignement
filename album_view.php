<?php 
include('db.php'); 

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$id = intval($_GET['id']);

$stmt = $conn->prepare("SELECT albums.AlbumId, albums.Title, albums.ArtistId, artists.Name as ArtistName
                        FROM albums 
                        LEFT JOIN artists ON albums.ArtistId = artists.ArtistId 
                        WHERE albums.AlbumId = ?");

if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header("Location: index.php");
    exit();
}

$album = $result->fetch_assoc();
$stmt->close();


$tracks_result = null;
$track_count = 0;


$table_check = $conn->query("SHOW TABLES LIKE 'tracks'");
if ($table_check && $table_check->num_rows > 0) {
    $tracks_stmt = $conn->prepare("SELECT TrackId, Name, Duration
                                   FROM tracks
                                   WHERE AlbumId = ?
                                   ORDER BY TrackId");
    
    if ($tracks_stmt !== false) {
        $tracks_stmt->bind_param("i", $id);
        $tracks_stmt->execute();
        $tracks_result = $tracks_stmt->get_result();
        $track_count = $tracks_result->num_rows;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($album['Title']) ?> - Chinook Music Database</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="album-header">
            <a href="index.php" class="back-link">← Back to Albums</a>
            <div class="album-info">
                <h1>🎵 <?= htmlspecialchars($album['Title']) ?></h1>
                <h2>by <?= htmlspecialchars($album['ArtistName'] ?? 'Unknown Artist') ?></h2>
            </div>
            <div class="album-actions">
                <a href="album_form.php?id=<?= $album['AlbumId'] ?>" class="btn btn-primary">Edit Album</a>
                <a href="album_delete.php?id=<?= $album['AlbumId'] ?>" class="btn btn-danger" 
                   onclick="return confirm('Are you sure you want to delete this album and all its tracks?')">Delete Album</a>
            </div>
        </div>
        
        <div class="tracks-section">
            <h3>Tracks (<?= $track_count ?>)</h3>
            
            <?php if ($tracks_result && $track_count > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Track Name</th>
                                <th>Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $track_number = 1;
                            while($track = $tracks_result->fetch_assoc()): 
                            ?>
                                <tr>
                                    <td><?= $track_number++ ?></td>
                                    <td><strong><?= htmlspecialchars($track['Name']) ?></strong></td>
                                    <td><?= $track['Duration'] ? gmdate("i:s", $track['Duration']) : 'N/A' ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="album-stats">
                    <div class="stat">
                        <strong>Total Tracks:</strong> <?= $track_count ?>
                    </div>
                    <div class="stat">
                        <strong>Total Duration:</strong> 
                        <?php
                       
                        $tracks_result->data_seek(0); // Reset pointer
                        $total_duration = 0;
                        while($track = $tracks_result->fetch_assoc()) {
                            $total_duration += $track['Duration'];
                        }
                        echo gmdate("H:i:s", $total_duration);
                        ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h4>No tracks found</h4>
                    <p>This album doesn't have any tracks in the database.</p>
                    <a href="album_form.php?id=<?= $album['AlbumId'] ?>" class="btn btn-primary">Add Tracks</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
    .album-stats {
        display: flex;
        gap: 30px;
        margin-top: 20px;
        padding: 20px;
        background: #f8f9ff;
        border-radius: 8px;
        border-left: 4px solid #667eea;
    }
    
    .stat {
        font-size: 1rem;
        color: #495057;
    }
    
    .stat strong {
        color: #667eea;
    }
    
    @media (max-width: 768px) {
        .album-stats {
            flex-direction: column;
            gap: 10px;
        }
    }
    </style>

    <?php
    // Close statements
    if (isset($tracks_stmt)) {
        $tracks_stmt->close();
    }
    ?>
</body>
</html>
