<?php 
include('db.php'); 


$created_at_exists = false;
$column_check = $conn->query("SHOW COLUMNS FROM albums LIKE 'created_at'");
if ($column_check && $column_check->num_rows > 0) {
    $created_at_exists = true;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$artist_filter = isset($_GET['artist']) ? intval($_GET['artist']) : 0; 
$sort = isset($_GET['sort']) ? $_GET['sort'] : ($created_at_exists ? 'created_at' : 'AlbumId');
$order = isset($_GET['order']) ? $_GET['order'] : ($created_at_exists && $sort == 'created_at' ? 'DESC' : 'ASC');

if ($sort == 'created_at' && !isset($_GET['order'])) {
    $order = 'DESC';
}


$items_per_page = 6;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(albums.Title LIKE ? OR artists.Name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if ($artist_filter > 0) {
    $where_conditions[] = "albums.ArtistId = ?";
    $params[] = $artist_filter;
    $types .= 'i';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$valid_sorts = ['AlbumId', 'Title', 'Name'];
if ($created_at_exists) {
    $valid_sorts[] = 'created_at';
}

if (!in_array($sort, $valid_sorts)) {
    $sort = $created_at_exists ? 'created_at' : 'AlbumId';
}

$order = ($order === 'DESC') ? 'DESC' : 'ASC';

$sort_column = $sort;
if ($sort === 'Name') {
    $sort_column = 'artists.Name';
} elseif ($sort === 'Title') {
    $sort_column = 'albums.Title';
} elseif ($sort === 'created_at' && $created_at_exists) {
    $sort_column = 'albums.created_at';
} else {
    $sort_column = 'albums.AlbumId';
}


$tracks_exist = false;
$table_check = $conn->query("SHOW TABLES LIKE 'tracks'");
if ($table_check && $table_check->num_rows > 0) {
    $tracks_exist = true;
}


$count_query = "SELECT COUNT(*) as total FROM albums 
                LEFT JOIN artists ON albums.ArtistId = artists.ArtistId 
                $where_clause";

if (!empty($params)) {
    $count_stmt = $conn->prepare($count_query);
    if ($count_stmt === false) {
        die('Prepare failed: ' . htmlspecialchars($conn->error));
    }
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total_items = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $count_result = $conn->query($count_query);
    if ($count_result === false) {
        die('Query failed: ' . htmlspecialchars($conn->error));
    }
    $total_items = $count_result->fetch_assoc()['total'];
}

$total_pages = ceil($total_items / $items_per_page);

$select_fields = "albums.AlbumId, albums.Title, albums.ArtistId, artists.Name as ArtistName";
if ($tracks_exist) {
    $select_fields .= ", (SELECT COUNT(*) FROM tracks WHERE tracks.AlbumId = albums.AlbumId) as TrackCount";
} else {
    $select_fields .= ", 0 as TrackCount";
}
if ($created_at_exists) {
    $select_fields .= ", albums.created_at";
}

$query = "SELECT $select_fields
          FROM albums 
          LEFT JOIN artists ON albums.ArtistId = artists.ArtistId 
          $where_clause
          ORDER BY $sort_column $order 
          LIMIT $items_per_page OFFSET $offset";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();


$artists_result = $conn->query("SELECT ArtistId, Name FROM artists ORDER BY Name");
if ($artists_result === false) {
    die('Artists query failed: ' . htmlspecialchars($conn->error));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chinook Music Database</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>🎵 Chinook Music Database</h1>
        
        <?php if (!$created_at_exists): ?>
        <div class="info-banner">
            <p><strong>Note:</strong> created_at column not found. <a href="add_created_at.php">Click here to add timestamp tracking</a></p>
        </div>
        <?php endif; ?>
        
        <div class="navigation">
            <a href="index.php" class="nav-link active">Albums</a>
            <a href="artists.php" class="nav-link">Artists</a>
            <?php if ($tracks_exist): ?>
            <!-- <a href="#" class="nav-link">Tracks</a> -->
            <?php endif; ?>
        </div>
        
        <?php if ($created_at_exists && $sort == 'created_at' && $order == 'DESC'): ?>
        <div class="newest-first-banner">
            <span class="icon">🆕</span>
            <strong>Showing newest albums first</strong> - Recently added albums appear at the top
        </div>
        <?php endif; ?>
        
        <div class="controls-section">
            <div class="search-controls">
                <form method="GET" class="search-form">
                    <input type="text" name="search" placeholder="Search albums or artists..." 
                           value="<?= htmlspecialchars($search) ?>" class="search-input">
                    
                    <select name="artist" class="filter-select">
                        <option value="0">All Artists</option>
                        <?php if ($artists_result && $artists_result->num_rows > 0): ?>
                            <?php while($artist = $artists_result->fetch_assoc()): ?>
                                <option value="<?= $artist['ArtistId'] ?>" 
                                        <?= $artist_filter == $artist['ArtistId'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($artist['Name']) ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                    
                    <select name="sort" class="sort-select">
                        <option value="AlbumId" <?= $sort == 'AlbumId' ? 'selected' : '' ?>>Sort by ID</option>
                        <option value="Title" <?= $sort == 'Title' ? 'selected' : '' ?>>Sort by Title</option>
                        <option value="Name" <?= $sort == 'Name' ? 'selected' : '' ?>>Sort by Artist</option>
                        <?php if ($created_at_exists): ?>
                        <option value="created_at" <?= $sort == 'created_at' ? 'selected' : '' ?>>Date Added (Newest First)</option>
                        <?php endif; ?>
                    </select>
                    
                    <select name="order" class="order-select">
                        <option value="ASC" <?= $order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                        <option value="DESC" <?= $order == 'DESC' ? 'selected' : '' ?>>Descending</option>
                    </select>
                    
                    <button type="submit" class="search-btn">Search</button>
                    <a href="index.php" class="clear-btn">Clear</a>
                </form>
            </div>
            
            <div class="header-actions">
                <a href="album_form.php" class="add-button">+ Add New Album</a>
                <div class="pagination-info">
                    Showing <?= min($offset + 1, $total_items) ?>-<?= min($offset + $items_per_page, $total_items) ?> of <?= $total_items ?> albums
                    <?php if ($created_at_exists && $sort == 'created_at' && $order == 'DESC'): ?>
                        <span class="sort-indicator">📅 Newest First</span>
                    <?php elseif ($created_at_exists && $sort == 'created_at' && $order == 'ASC'): ?>
                        <span class="sort-indicator">📅 Oldest First</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Album Title</th>
                        <th>Artist</th>
                        <?php if ($tracks_exist): ?>
                        <th>Tracks</th>
                        <?php endif; ?>
                        <?php if ($created_at_exists): ?>
                        <th>Date Added</th>
                        <?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($row['Title']) ?></strong>
                                    <?php if ($created_at_exists && isset($row['created_at'])): ?>
                                        <?php 
                                        $created_date = new DateTime($row['created_at']);
                                        $now = new DateTime();
                                        $diff = $now->diff($created_date);
                                        
                                        if ($diff->days == 0) {
                                            echo '<span class="new-badge">NEW</span>';
                                        } elseif ($diff->days <= 7) {
                                            echo '<span class="recent-badge">RECENT</span>';
                                        }
                                        ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['ArtistName'])): ?>
                                        <span class="artist-link">
                                            <?= htmlspecialchars($row['ArtistName']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Unknown Artist</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($tracks_exist): ?>
                                <td>
                                    <span class="track-count">
                                        <?= $row['TrackCount'] ?> tracks
                                    </span>
                                </td>
                                <?php endif; ?>
                                <?php if ($created_at_exists && isset($row['created_at'])): ?>
                                <td>
                                    <span class="date-added">
                                        <?= date('M j, Y', strtotime($row['created_at'])) ?>
                                    </span>
                                    <small class="time-added">
                                        <?= date('g:i A', strtotime($row['created_at'])) ?>
                                    </small>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div class="action-links">
                                        <a href="album_view.php?id=<?= $row['AlbumId'] ?>" class="view-btn">View</a>
                                        <a href="album_form.php?id=<?= $row['AlbumId'] ?>" class="edit-btn">Edit</a>
                                        <a href="album_delete.php?id=<?= $row['AlbumId'] ?>" class="delete-btn" 
                                           onclick="return confirm('Are you sure you want to delete this album?')">Delete</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= ($tracks_exist ? 1 : 0) + ($created_at_exists ? 1 : 0) + 4 ?>" class="empty-state">
                                <h3>No albums found</h3>
                                <p><?= !empty($search) ? 'Try adjusting your search criteria.' : 'Start by adding your first album!' ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <div class="pagination-container">
                <?php
                $base_url = "?search=" . urlencode($search) . "&artist=$artist_filter&sort=$sort&order=$order";
                ?>
                
                <?php if ($current_page > 1): ?>
                    <a href="<?= $base_url ?>&page=1" class="pagination-btn">First</a>
                    <a href="<?= $base_url ?>&page=<?= $current_page - 1 ?>" class="pagination-btn">Previous</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <a href="<?= $base_url ?>&page=<?= $i ?>" 
                       class="pagination-btn <?= $i == $current_page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($current_page < $total_pages): ?>
                    <a href="<?= $base_url ?>&page=<?= $current_page + 1 ?>" class="pagination-btn">Next</a>
                    <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="pagination-btn">Last</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <style>
    .sort-indicator {
        color: #667eea;
        font-weight: 600;
        font-size: 0.85rem;
    }
    
    .new-badge {
        background: #28a745;
        color: white;
        font-size: 0.7rem;
        padding: 2px 6px;
        border-radius: 3px;
        margin-left: 8px;
        font-weight: 600;
    }
    
    .recent-badge {
        background: #ffc107;
        color: #212529;
        font-size: 0.7rem;
        padding: 2px 6px;
        border-radius: 3px;
        margin-left: 8px;
        font-weight: 600;
    }
    
    .date-added {
        display: block;
        font-weight: 500;
        color: #495057;
    }
    
    .time-added {
        display: block;
        color: #6c757d;
        font-size: 0.8rem;
    }
    
    .info-banner {
        background: #fff3cd;
        border-bottom: 1px solid #ffeaa7;
        padding: 15px 30px;
        text-align: center;
    }
    
    .info-banner p {
        margin: 0;
        color: #856404;
    }
    
    .info-banner a {
        color: #667eea;
        text-decoration: none;
        font-weight: 500;
    }
    
    .info-banner a:hover {
        text-decoration: underline;
    }

    .newest-first-banner {
        background: linear-gradient(135deg, #e8f5e8 0%, #f0f8f0 100%);
        border: 1px solid #c3e6cb;
        border-radius: 6px;
        padding: 12px 20px;
        margin: 15px 30px;
        text-align: center;
        color: #155724;
        font-weight: 500;
    }

    .newest-first-banner .icon {
        font-size: 1.2rem;
        margin-right: 8px;
    }
    </style>

    <?php
    // Close the statement
    if (isset($stmt)) {
        $stmt->close();
    }
    ?>
</body>
</html>
