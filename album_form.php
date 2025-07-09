<?php 
include('db.php'); 

// Check if created_at column exists
$created_at_exists = false;
$column_check = $conn->query("SHOW COLUMNS FROM albums LIKE 'created_at'");
if ($column_check && $column_check->num_rows > 0) {
    $created_at_exists = true;
}

$album = ['AlbumId' => '', 'Title' => '', 'ArtistId' => ''];
$isEdit = false;
$errors = [];
$success = '';
$existing_tracks = [];

// Check if tracks table exists
$tracks_exist = false;
$table_check = $conn->query("SHOW TABLES LIKE 'tracks'");
if ($table_check && $table_check->num_rows > 0) {
    $tracks_exist = true;
}

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM albums WHERE AlbumId = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $album = $result->fetch_assoc();
        $isEdit = true;
        
        // Get existing tracks if editing
        if ($tracks_exist) {
            $tracks_stmt = $conn->prepare("SELECT TrackId, Name, Duration FROM tracks WHERE AlbumId = ? ORDER BY TrackId");
            $tracks_stmt->bind_param("i", $id);
            $tracks_stmt->execute();
            $tracks_result = $tracks_stmt->get_result();
            while ($track = $tracks_result->fetch_assoc()) {
                $existing_tracks[] = $track;
            }
        }
    } else {
        header("Location: index.php");
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = $_POST['AlbumId'];
    $title = trim($_POST['Title']);
    $artist = intval($_POST['ArtistId']);
    
    // Get track data
    $track_names = isset($_POST['track_names']) ? $_POST['track_names'] : [];
    $track_durations = isset($_POST['track_durations']) ? $_POST['track_durations'] : [];
    
    // Validation
    if (empty($title)) {
        $errors[] = "Album title is required.";
    } elseif (strlen($title) > 95) {
        $errors[] = "Album title must be 95 characters or less.";
    }
    
    if ($artist <= 0) {
        $errors[] = "Please select a valid artist.";
    } else {
        // Check if artist exists
        $artist_check = $conn->prepare("SELECT ArtistId FROM artists WHERE ArtistId = ?");
        $artist_check->bind_param("i", $artist);
        $artist_check->execute();
        if ($artist_check->get_result()->num_rows == 0) {
            $errors[] = "Selected artist does not exist.";
        }
    }
    
    // Validate tracks
    if ($tracks_exist && !empty($track_names)) {
        foreach ($track_names as $index => $track_name) {
            $track_name = trim($track_name);
            if (!empty($track_name)) {
                if (strlen($track_name) > 120) {
                    $errors[] = "Track name '" . substr($track_name, 0, 30) . "...' is too long (max 120 characters).";
                }
                
                // Validate duration (convert mm:ss to seconds)
                if (!empty($track_durations[$index])) {
                    $duration = $track_durations[$index];
                    if (!preg_match('/^\d{1,2}:\d{2}$/', $duration)) {
                        $errors[] = "Invalid duration format for track '$track_name'. Use MM:SS format.";
                    }
                }
            }
        }
    }
    
    // Check for duplicate album title by same artist
    if (empty($errors)) {
        if ($id) {
            $dup_check = $conn->prepare("SELECT AlbumId FROM albums WHERE Title = ? AND ArtistId = ? AND AlbumId != ?");
            $dup_check->bind_param("sii", $title, $artist, $id);
        } else {
            $dup_check = $conn->prepare("SELECT AlbumId FROM albums WHERE Title = ? AND ArtistId = ?");
            $dup_check->bind_param("si", $title, $artist);
        }
        $dup_check->execute();
        if ($dup_check->get_result()->num_rows > 0) {
            $errors[] = "This artist already has an album with this title.";
        }
    }
    
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Save album
            if ($id) {
                $stmt = $conn->prepare("UPDATE albums SET Title = ?, ArtistId = ? WHERE AlbumId = ?");
                $stmt->bind_param("sii", $title, $artist, $id);
                $success = "Album updated successfully!";
            } else {
                // Insert with created_at if column exists
                if ($created_at_exists) {
                    $stmt = $conn->prepare("INSERT INTO albums (Title, ArtistId, created_at) VALUES (?, ?, NOW())");
                } else {
                    $stmt = $conn->prepare("INSERT INTO albums (Title, ArtistId) VALUES (?, ?)");
                }
                $stmt->bind_param("si", $title, $artist);
                $success = "Album created successfully!";
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to save album: " . $conn->error);
            }
            
            if (!$id) {
                $id = $conn->insert_id;
            }
            
            // Save tracks if tracks table exists
            if ($tracks_exist && !empty($track_names)) {
                // Delete existing tracks if editing
                if ($isEdit) {
                    $delete_tracks = $conn->prepare("DELETE FROM tracks WHERE AlbumId = ?");
                    $delete_tracks->bind_param("i", $id);
                    $delete_tracks->execute();
                }
                
                // Insert new tracks
                $track_insert = $conn->prepare("INSERT INTO tracks (Name, AlbumId, Duration) VALUES (?, ?, ?)");
                
                foreach ($track_names as $index => $track_name) {
                    $track_name = trim($track_name);
                    if (!empty($track_name)) {
                        // Convert duration to seconds
                        $duration_seconds = 0;
                        if (!empty($track_durations[$index])) {
                            $duration_parts = explode(':', $track_durations[$index]);
                            if (count($duration_parts) == 2) {
                                $minutes = intval($duration_parts[0]);
                                $seconds = intval($duration_parts[1]);
                                $duration_seconds = ($minutes * 60) + $seconds;
                            }
                        }
                        
                        $track_insert->bind_param("sii", $track_name, $id, $duration_seconds);
                        if (!$track_insert->execute()) {
                            throw new Exception("Failed to save track '$track_name': " . $conn->error);
                        }
                    }
                }
            }
            
            $conn->commit();
            
            // Redirect after 2 seconds
            header("refresh:2;url=album_view.php?id=$id");
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Get all artists for dropdown
$artists_result = $conn->query("SELECT ArtistId, Name FROM artists ORDER BY Name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit Album' : 'Add New Album' ?> - Chinook Music Database</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .tracks-section {
            margin-top: 30px;
            padding: 25px;
            background: #f8f9ff;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .tracks-section h3 {
            color: #667eea;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .track-row {
            display: grid;
            grid-template-columns: 2fr 120px 40px;
            gap: 15px;
            margin-bottom: 15px;
            align-items: end;
        }
        
        .track-input {
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 0.9rem;
            font-family: "Roboto", sans-serif;
        }
        
        .track-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        
        .remove-track {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px;
            cursor: pointer;
            font-size: 0.8rem;
            height: 38px;
        }
        
        .remove-track:hover {
            background: #c82333;
        }
        
        .add-track-btn {
            background: #28a745;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 500;
            margin-top: 10px;
        }
        
        .add-track-btn:hover {
            background: #218838;
        }
        
        .track-headers {
            display: grid;
            grid-template-columns: 2fr 120px 40px;
            gap: 15px;
            margin-bottom: 10px;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }
        
        .existing-tracks {
            background: #fff3cd;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 3px solid #ffc107;
        }
        
        .existing-tracks h4 {
            color: #856404;
            margin-bottom: 10px;
        }
        
        .existing-track {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ffeaa7;
        }
        
        .existing-track:last-child {
            border-bottom: none;
        }
        
        @media (max-width: 768px) {
            .track-row,
            .track-headers {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .track-headers {
                display: none;
            }
            
            .track-input {
                margin-bottom: 5px;
            }
            
            .remove-track {
                width: 100%;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="form-container" style="max-width: 800px;">
        <h1><?= $isEdit ? '✏️ Edit Album' : '➕ Add New Album' ?></h1>
        
        <div class="form-content">
            <a href="index.php" class="back-link">Back to Album List</a>
            
            <?php if (!$created_at_exists): ?>
                <div class="info-banner" style="margin: 20px 0; padding: 15px; background: #fff3cd; border-radius: 6px;">
                    <p><strong>Note:</strong> created_at column not found. <a href="add_created_at.php">Add timestamp tracking</a> to show latest albums first.</p>
                </div>
            <?php endif; ?>
            
            <?php if (!$tracks_exist): ?>
                <div class="info-banner" style="margin: 20px 0; padding: 15px; background: #fff3cd; border-radius: 6px;">
                    <p><strong>Note:</strong> Tracks table not found. You can still create albums, but track functionality will be limited. 
                    <a href="setup_database.php">Set up complete database</a></p>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="error-messages">
                    <h4>Please fix the following errors:</h4>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <p><?= htmlspecialchars($success) ?></p>
                    <p><small>Redirecting to album details...</small></p>
                </div>
            <?php endif; ?>
            
            <form method="post" novalidate id="albumForm">
                <input type="hidden" name="AlbumId" value="<?= htmlspecialchars($album['AlbumId']) ?>">
                
                <div class="form-group">
                    <label for="title">Album Title *</label>
                    <input type="text" id="title" name="Title" 
                           value="<?= htmlspecialchars($album['Title']) ?>" 
                           required maxlength="95" 
                           placeholder="Enter album title">
                    <small>Maximum 95 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="artist">Artist *</label>
                    <select id="artist" name="ArtistId" required>
                        <option value="">Select an artist</option>
                        <?php if ($artists_result): ?>
                            <?php $artists_result->data_seek(0); // Reset pointer ?>
                            <?php while($artist_row = $artists_result->fetch_assoc()): ?>
                                <option value="<?= $artist_row['ArtistId'] ?>" 
                                        <?= $album['ArtistId'] == $artist_row['ArtistId'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($artist_row['Name']) ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                    <small>Don't see the artist? <a href="artist_form.php" target="_blank">Add a new artist</a></small>
                </div>
                
                <?php if ($tracks_exist): ?>
                <div class="tracks-section">
                    <h3>🎵 Album Tracks</h3>
                    
                    <?php if ($isEdit && !empty($existing_tracks)): ?>
                    <div class="existing-tracks">
                        <h4>Current Tracks (will be replaced):</h4>
                        <?php foreach ($existing_tracks as $track): ?>
                        <div class="existing-track">
                            <span><strong><?= htmlspecialchars($track['Name']) ?></strong></span>
                            <span>
                                <?= $track['Duration'] ? gmdate("i:s", $track['Duration']) : 'N/A' ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="track-headers">
                        <div>Track Name</div>
                        <div>Duration (MM:SS)</div>
                        <div></div>
                    </div>
                    
                    <div id="tracksContainer">
                        <!-- Initial track row -->
                        <div class="track-row">
                            <input type="text" name="track_names[]" placeholder="Enter track name" class="track-input" maxlength="120">
                            <input type="text" name="track_durations[]" placeholder="3:45" pattern="\d{1,2}:\d{2}" class="track-input">
                            <button type="button" class="remove-track" onclick="removeTrack(this)">×</button>
                        </div>
                    </div>
                    
                    <button type="button" class="add-track-btn" onclick="addTrack()">+ Add Another Track</button>
                    
                    <div style="margin-top: 15px; padding: 10px; background: #e9ecef; border-radius: 4px; font-size: 0.9rem;">
                        <strong>Tips:</strong>
                        <ul style="margin: 5px 0 0 20px;">
                            <li>Track names are required (max 120 characters), duration is optional</li>
                            <li>Duration format: MM:SS (e.g., 3:45 for 3 minutes 45 seconds)</li>
                            <li>You can add tracks later by editing the album</li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">
                        <?= $isEdit ? 'Update Album & Tracks' : 'Save Album & Tracks' ?>
                    </button>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function addTrack() {
            const container = document.getElementById('tracksContainer');
            const trackRow = document.createElement('div');
            trackRow.className = 'track-row';
            trackRow.innerHTML = `
                <input type="text" name="track_names[]" placeholder="Enter track name" class="track-input" maxlength="120">
                <input type="text" name="track_durations[]" placeholder="3:45" pattern="\\d{1,2}:\\d{2}" class="track-input">
                <button type="button" class="remove-track" onclick="removeTrack(this)">×</button>
            `;
            container.appendChild(trackRow);
        }
        
        function removeTrack(button) {
            const container = document.getElementById('tracksContainer');
            if (container.children.length > 1) {
                button.parentElement.remove();
            } else {
                alert('You must have at least one track row.');
            }
        }
        
        // Add some default tracks for new albums
        <?php if (!$isEdit): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Add a few more track rows for new albums
            addTrack();
            addTrack();
        });
        <?php endif; ?>
        
        // Form validation
        document.getElementById('albumForm').addEventListener('submit', function(e) {
            const trackNames = document.querySelectorAll('input[name="track_names[]"]');
            const durations = document.querySelectorAll('input[name="track_durations[]"]');
            let hasValidTrack = false;
            
            // Check if at least one track has a name
            trackNames.forEach(function(input) {
                if (input.value.trim() !== '') {
                    hasValidTrack = true;
                }
            });
            
            // Validate duration format
            durations.forEach(function(input) {
                if (input.value && !input.value.match(/^\d{1,2}:\d{2}$/)) {
                    alert('Duration must be in MM:SS format (e.g., 3:45)');
                    input.focus();
                    e.preventDefault();
                    return false;
                }
            });
            
            <?php if ($tracks_exist): ?>
            if (!hasValidTrack) {
                if (!confirm('No tracks were added. Do you want to create an album without tracks?')) {
                    e.preventDefault();
                    return false;
                }
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>
