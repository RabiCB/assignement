<?php 
include('db.php'); 

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
    $artist_option = $_POST['artist_option']; 
    $artist_id = 0;
    
    $track_names = isset($_POST['track_names']) ? $_POST['track_names'] : [];
    $track_durations = isset($_POST['track_durations']) ? $_POST['track_durations'] : [];
    
    if (empty($title)) {
        $errors[] = "Album title is required.";
    } elseif (strlen($title) > 95) {
        $errors[] = "Album title must be 95 characters or less.";
    }
    
    if ($artist_option == 'existing') {
        $artist_id = intval($_POST['existing_artist']);
        if ($artist_id <= 0) {
            $errors[] = "Please select a valid artist.";
        } else {
            $artist_check = $conn->prepare("SELECT ArtistId FROM artists WHERE ArtistId = ?");
            $artist_check->bind_param("i", $artist_id);
            $artist_check->execute();
            if ($artist_check->get_result()->num_rows == 0) {
                $errors[] = "Selected artist does not exist.";
            }
        }
    } elseif ($artist_option == 'new') {
        $new_artist_name = trim($_POST['new_artist_name']);
        if (empty($new_artist_name)) {
            $errors[] = "New artist name is required.";
        } elseif (strlen($new_artist_name) > 120) {
            $errors[] = "Artist name must be 120 characters or less.";
        } else {
            $existing_artist_check = $conn->prepare("SELECT ArtistId FROM artists WHERE Name = ?");
            $existing_artist_check->bind_param("s", $new_artist_name);
            $existing_artist_check->execute();
            $existing_result = $existing_artist_check->get_result();
            
            if ($existing_result->num_rows > 0) {
                $errors[] = "An artist with this name already exists. Please select from existing artists.";
            }
        }
    } else {
        $errors[] = "Please select an artist option.";
    }
    
    if ($tracks_exist && !empty($track_names)) {
        foreach ($track_names as $index => $track_name) {
            $track_name = trim($track_name);
            if (!empty($track_name)) {
                if (strlen($track_name) > 120) {
                    $errors[] = "Track name '" . substr($track_name, 0, 30) . "...' is too long (max 120 characters).";
                }
                
                if (!empty($track_durations[$index])) {
                    $duration = $track_durations[$index];
                    if (!preg_match('/^\d{1,2}:\d{2}$/', $duration)) {
                        $errors[] = "Invalid duration format for track '$track_name'. Use MM:SS format.";
                    }
                }
            }
        }
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            if ($artist_option == 'new') {
                $insert_artist = $conn->prepare("INSERT INTO artists (Name) VALUES (?)");
                $insert_artist->bind_param("s", $new_artist_name);
                if (!$insert_artist->execute()) {
                    throw new Exception("Failed to create new artist: " . $conn->error);
                }
                $artist_id = $conn->insert_id;
            }

            if ($id) {
                $dup_check = $conn->prepare("SELECT AlbumId FROM albums WHERE Title = ? AND ArtistId = ? AND AlbumId != ?");
                $dup_check->bind_param("sii", $title, $artist_id, $id);
            } else {
                $dup_check = $conn->prepare("SELECT AlbumId FROM albums WHERE Title = ? AND ArtistId = ?");
                $dup_check->bind_param("si", $title, $artist_id);
            }
            $dup_check->execute();
            if ($dup_check->get_result()->num_rows > 0) {
                throw new Exception("This artist already has an album with this title.");
            }
            

            if ($id) {
                $stmt = $conn->prepare("UPDATE albums SET Title = ?, ArtistId = ? WHERE AlbumId = ?");
                $stmt->bind_param("sii", $title, $artist_id, $id);
                $success = "Album updated successfully!";
            } else {
                
                if ($created_at_exists) {
                    $stmt = $conn->prepare("INSERT INTO albums (Title, ArtistId, created_at) VALUES (?, ?, NOW())");
                } else {
                    $stmt = $conn->prepare("INSERT INTO albums (Title, ArtistId) VALUES (?, ?)");
                }
                $stmt->bind_param("si", $title, $artist_id);
                $success = "Album created successfully!";
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to save album: " . $conn->error);
            }
            
            if (!$id) {
                $id = $conn->insert_id;
            }
            
           
            if ($tracks_exist && !empty($track_names)) {
              
                if ($isEdit) {
                    $delete_tracks = $conn->prepare("DELETE FROM tracks WHERE AlbumId = ?");
                    $delete_tracks->bind_param("i", $id);
                    $delete_tracks->execute();
                }
                
                
                $track_insert = $conn->prepare("INSERT INTO tracks (Name, AlbumId, Milliseconds) VALUES (?, ?, ?)");
                
                foreach ($track_names as $index => $track_name) {
                    $track_name = trim($track_name);
                    if (!empty($track_name)) {

                        $duration_ms = 0;
                        if (!empty($track_durations[$index])) {
                            $duration_parts = explode(':', $track_durations[$index]);
                            if (count($duration_parts) == 2) {
                                $minutes = intval($duration_parts[0]);
                                $seconds = intval($duration_parts[1]);
                                $duration_ms = (($minutes * 60) + $seconds) * 1000;
                            }
                        }
                        
                        $track_insert->bind_param("sii", $track_name, $id, $duration_ms);
                        if (!$track_insert->execute()) {
                            throw new Exception("Failed to save track '$track_name': " . $conn->error);
                        }
                    }
                }
            }
            
            $conn->commit();
           
            header("refresh:2;url=album_view.php?id=$id");
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}


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
        .artist-selection {
            background: #f8f9ff;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            margin: 20px 0;
        }
        
        .artist-option {
            margin: 15px 0;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .artist-option.selected {
            border-color: #667eea;
            background: #fafbff;
        }
        
        .artist-option input[type="radio"] {
            margin-right: 10px;
            transform: scale(1.2);
        }
        
        .artist-option label {
            font-weight: 600;
            color: #495057;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        
        .artist-fields {
            margin-top: 15px;
            display: none;
        }
        
        .artist-fields.active {
            display: block;
        }
        
        .new-artist-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            font-family: "Roboto", sans-serif;
            transition: all 0.3s ease;
        }
        
        .new-artist-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
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
                
                <div class="artist-selection">
                    <h3>🎤 Artist Selection</h3>
                    
                    <div class="artist-option" id="existing-option">
                        <label>
                            <input type="radio" name="artist_option" value="existing" 
                                   <?= $isEdit ? 'checked' : '' ?> onchange="toggleArtistFields()">
                            Select Existing Artist
                        </label>
                        <div class="artist-fields" id="existing-fields">
                            <select name="existing_artist"  class="form-group filter-select">
                                <option  value="">Choose an artist</option>
                                <?php if ($artists_result): ?>
                                    <?php $artists_result->data_seek(0); ?>
                                    <?php while($artist_row = $artists_result->fetch_assoc()): ?>
                                        <option value="<?= $artist_row['ArtistId'] ?>" 
                                                <?= $album['ArtistId'] == $artist_row['ArtistId'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($artist_row['Name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="artist-option" id="new-option">
                        <label>
                            <input type="radio" name="artist_option" value="new" 
                                   <?= !$isEdit ? 'checked' : '' ?> onchange="toggleArtistFields()">
                            Add New Artist
                        </label>
                        <div class="artist-fields" id="new-fields">
                            <input type="text" name="new_artist_name" 
                                   placeholder="Enter new artist name" 
                                   maxlength="120" 
                                   class="new-artist-input">
                            <small style="color: #6c757d; margin-top: 5px; display: block;">
                                Maximum 120 characters. Artist will be created automatically.
                            </small>
                        </div>
                    </div>
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
                            <li>Track names are optional (max 120 characters)</li>
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
        function toggleArtistFields() {
            const existingRadio = document.querySelector('input[name="artist_option"][value="existing"]');
            const newRadio = document.querySelector('input[name="artist_option"][value="new"]');
            const existingFields = document.getElementById('existing-fields');
            const newFields = document.getElementById('new-fields');
            const existingOption = document.getElementById('existing-option');
            const newOption = document.getElementById('new-option');
        
            existingOption.classList.remove('selected');
            newOption.classList.remove('selected');
            
          
            existingFields.classList.remove('active');
            newFields.classList.remove('active');
            
            if (existingRadio.checked) {
                existingOption.classList.add('selected');
                existingFields.classList.add('active');
            } else if (newRadio.checked) {
                newOption.classList.add('selected');
                newFields.classList.add('active');
            }
        }
        
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
        
    
        document.addEventListener('DOMContentLoaded', function() {
            toggleArtistFields();
            
           
            <?php if (!$isEdit): ?>
            addTrack();
            addTrack();
            <?php endif; ?>
        });
        
      
        document.getElementById('albumForm').addEventListener('submit', function(e) {
            const artistOption = document.querySelector('input[name="artist_option"]:checked');
            
            if (!artistOption) {
                alert('Please select an artist option.');
                e.preventDefault();
                return false;
            }
            
            if (artistOption.value === 'existing') {
                const existingArtist = document.querySelector('select[name="existing_artist"]').value;
                if (!existingArtist) {
                    alert('Please select an existing artist.');
                    e.preventDefault();
                    return false;
                }
            } else if (artistOption.value === 'new') {
                const newArtistName = document.querySelector('input[name="new_artist_name"]').value.trim();
                if (!newArtistName) {
                    alert('Please enter a new artist name.');
                    e.preventDefault();
                    return false;
                }
            }
            
         
            const durations = document.querySelectorAll('input[name="track_durations[]"]');
            for (let input of durations) {
                if (input.value && !input.value.match(/^\d{1,2}:\d{2}$/)) {
                    alert('Duration must be in MM:SS format (e.g., 3:45)');
                    input.focus();
                    e.preventDefault();
                    return false;
                }
            }
        });
    </script>
</body>
</html>
