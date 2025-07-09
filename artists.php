<?php
include('db.php');

// Search & sort parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'Name';
$order = (isset($_GET['order']) && strtoupper($_GET['order']) === 'DESC') ? 'DESC' : 'ASC';

// Pagination setup
$items_per_page = 6;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// WHERE clause
$where_clause = '';
$params = [];
$types = '';

if (!empty($search)) {
    $where_clause = "WHERE Name LIKE ?";
    $params[] = "%$search%";
    $types .= 's';
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM artists $where_clause";
$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_items = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_items / $items_per_page);

// Fetch artists
$query = "SELECT ArtistId, Name FROM artists $where_clause ORDER BY $sort $order LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);

$bind_types = $types . 'ii';
$bind_params = $params;
$bind_params[] = $items_per_page;
$bind_params[] = $offset;

$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Artists - Chinook</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <h1>🎤 Artists</h1>
 <div style="margin:1rem">
       <a href="index.php" class="back-link">← Back to Albums</a>
 </div>
    <div class="search-controls controls-section">
        <form method="GET" class="search-form">
            <input class="search-input" type="text" name="search" placeholder="Search artist..."
                   value="<?= htmlspecialchars($search) ?>">
            <select name="sort" class="sort-select">
                <option value="ArtistId" <?= $sort == 'ArtistId' ? 'selected' : '' ?>>Sort by ID</option>
                <option value="Name" <?= $sort == 'Name' ? 'selected' : '' ?>>Sort by Name</option>
            </select>
            <select name="order" class="sort-select">
                <option value="ASC" <?= $order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                <option value="DESC" <?= $order == 'DESC' ? 'selected' : '' ?>>Descending</option>
            </select>
            <button type="submit" class="search-btn">Search</button>
            <a class="clear-btn" href="artists.php">Clear</a>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Artist Name</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['ArtistId'] ?></td>
                    <td><?= htmlspecialchars($row['Name']) ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="2">No artists found.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <div class="pagination-container">
                <?php
                $base_url = "?search=" . urlencode($search) . "&sort=$sort&order=$order";
                if ($current_page > 1):
                ?>
                    <a href="<?= $base_url ?>&page=1" class="pagination-btn">First</a>
                    <a href="<?= $base_url ?>&page=<?= $current_page - 1 ?>" class="pagination-btn">Previous</a>
                <?php endif; ?>

                <?php
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <a href="<?= $base_url ?>&page=<?= $i ?>" class="pagination-btn <?= $i == $current_page ? 'active' : '' ?>">
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
</body>
</html>
<?php $stmt->close(); ?>
