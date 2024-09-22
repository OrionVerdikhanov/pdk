<?php
session_start();
require 'db.php'; // Убедитесь, что файл db.php подключает PDO правильно

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

function getUser($pdo, $user_id) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

checkAuth();
$user_id = $_SESSION['user_id'];
$user = getUser($pdo, $user_id);

if (!$user) {
    // Если пользователь не найден в базе данных
    session_destroy();
    header('Location: login.php');
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($user['role'] == 'Main Admin' && isset($_POST['assign_pdk'])) {
        $pdk_date = $_POST['pdk_date'];
        $pdk_type = $_POST['pdk_type'];
        $assignments = $_POST['assignments'];

        if (empty($pdk_date) || empty($pdk_type) || empty($assignments)) {
            $error = "Пожалуйста, заполните все поля.";
        } else {
            try {
                $pdo->beginTransaction();

                // Вставка в таблицу pdk
                $stmt = $pdo->prepare('INSERT INTO pdk (date, type) VALUES (?, ?)');
                $stmt->execute([$pdk_date, $pdk_type]);
                $pdk_id = $pdo->lastInsertId();

                foreach ($assignments as $assignment) {
                    $staff_ids = $assignment['staff_ids'] ?? [];
                    $brigades = $assignment['brigades'] ?? [];

                    if (empty($staff_ids) || empty($brigades)) {
                        continue; // Пропустить неполные задания
                    }

                    foreach ($brigades as $brigade) {
                        $bush = trim($brigade['bush']);
                        $brigade_ids = $brigade['brigade_ids'] ?? [];

                        if (empty($bush) || empty($brigade_ids)) {
                            continue; // Пропустить неполные бригады
                        }

                        // Вставка в таблицу pdk_details
                        $stmt = $pdo->prepare('INSERT INTO pdk_details (pdk_id, bush) VALUES (?, ?)');
                        $stmt->execute([$pdk_id, $bush]);
                        $detail_id = $pdo->lastInsertId();

                        // Вставка в таблицу pdk_assignments
                        foreach ($staff_ids as $staff_id) {
                            if (!empty($staff_id)) {
                                $stmt = $pdo->prepare('INSERT INTO pdk_assignments (detail_id, user_id) VALUES (?, ?)');
                                $stmt->execute([$detail_id, $staff_id]);
                            }
                        }

                        // Вставка в таблицу pdk_brigades
                        foreach ($brigade_ids as $brigade_id) {
                            if (!empty($brigade_id)) {
                                $stmt = $pdo->prepare('INSERT INTO pdk_brigades (detail_id, brigade_id) VALUES (?, ?)');
                                $stmt->execute([$detail_id, $brigade_id]);
                            }
                        }
                    }
                }

                $pdo->commit();
                $success = "ПДК успешно назначено.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Произошла ошибка: " . $e->getMessage();
            }
        }
    }

    if ($user['role'] == 'Junior Admin' && isset($_POST['submit_act'])) {
        $detail_id = $_POST['detail_id'];
        $violations = $_POST['violations'] ?? [];
        $comments = $_POST['comments'] ?? [];
        $photos = $_FILES['photos'] ?? [];

        $photo_paths = [];
        if (!empty($photos['name'])) {
            foreach ($photos['name'] as $key => $name) {
                if ($photos['error'][$key] == 0 && !empty($name)) {
                    $tmp_name = $photos['tmp_name'][$key];
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $new_name = uniqid() . '.' . $ext;
                    $upload_dir = "uploads/";

                    // Убедитесь, что папка uploads существует и доступна для записи
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
                        $photo_paths[$key] = $new_name;
                    }
                }
            }
        }

        // Обработка и вставка данных в таблицу acts
        try {
            $stmt = $pdo->prepare('INSERT INTO acts (detail_id, user_id, date, violations, comments, photos) VALUES (?, ?, NOW(), ?, ?, ?)');
            $stmt->execute([
                $detail_id,
                $user_id,
                json_encode($violations),
                json_encode($comments),
                json_encode($photo_paths)
            ]);

            $success = "Акт нарушений успешно составлен.";
        } catch (Exception $e) {
            $error = "Произошла ошибка при создании акта: " . $e->getMessage();
        }
    }

    if ($user['role'] == 'Master' && isset($_POST['mark_violation'])) {
        $act_id = $_POST['act_id'];
        $violation_id = $_POST['violation_id'];
        $completion_date = $_POST['completion_date'];

        // Проверка формата даты (дд.мм.гггг)
        if (preg_match('/^(0[1-9]|[12][0-9]|3[01])\.(0[1-9]|1[012])\.(\d{4})$/', $completion_date, $matches)) {
            $completion_date_formatted = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        } else {
            $error = "Неверный формат даты. Используйте дд.мм.гггг.";
        }

        if (empty($error)) {
            try {
                $stmt = $pdo->prepare('INSERT INTO violation_completions (act_id, violation_id, user_id, completion_date) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $act_id,
                    $violation_id,
                    $user_id,
                    $completion_date_formatted
                ]);

                $success = "Нарушение отмечено как выполненное.";
            } catch (Exception $e) {
                $error = "Произошла ошибка при отметке нарушения: " . $e->getMessage();
            }
        }
    }
}

// Получение данных для разных ролей
if ($user['role'] == 'Main Admin') {
    $staff = $pdo->query('SELECT id, name FROM users WHERE role = "Junior Admin"')->fetchAll(PDO::FETCH_ASSOC);
    $brigades = $pdo->query('SELECT id, name FROM brigades')->fetchAll(PDO::FETCH_ASSOC);
    $pdks = $pdo->query('SELECT * FROM pdk')->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user['role'] == 'Junior Admin') {
    $stmt = $pdo->prepare('SELECT pd.*, p.date, p.type FROM pdk_assignments pa
                           JOIN pdk_details pd ON pa.detail_id = pd.id
                           JOIN pdk p ON pd.pdk_id = p.id
                           WHERE pa.user_id = ?');
    $stmt->execute([$user_id]);
    $assigned_pdks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user['role'] == 'Master') {
    $stmt = $pdo->prepare('SELECT acts.*, users.name AS admin_name FROM acts
                           JOIN pdk_details pd ON acts.detail_id = pd.id
                           JOIN pdk_brigades pb ON pd.id = pb.detail_id
                           JOIN users ON acts.user_id = users.id
                           WHERE pb.brigade_id = ?');
    $stmt->execute([$user['brigade_id']]);
    $acts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Личный кабинет</title>
    <style>
        /* Стили остаются такими же */
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; }
        .container { width: 80%; margin: 20px auto; background: #fff; padding: 20px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); }
        h1, h2, h3 { color: #333; }
        a { color: #007BFF; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .message { padding: 10px; margin-bottom: 20px; border-radius: 5px; }
        .error { background-color: #f8d7da; color: #721c24; }
        .success { background-color: #d4edda; color: #155724; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table, th, td { border: 1px solid #dee2e6; }
        th, td { padding: 8px; text-align: left; }
        th { background-color: #e9ecef; }
        form { margin-bottom: 20px; }
        label { display: block; margin-top: 10px; }
        input[type="text"], input[type="date"], select, textarea { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; }
        button { padding: 8px 12px; background-color: #28a745; color: #fff; border: none; cursor: pointer; margin-top: 10px; border-radius: 3px; }
        button:hover { background-color: #218838; }
        .assignment-group, .brigade-group { border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; position: relative; border-radius: 5px; }
        .remove-assignment, .remove-brigade { position: absolute; top: 10px; right: 10px; background-color: #dc3545; color: #fff; border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px; }
        .remove-assignment:hover, .remove-brigade:hover { background-color: #c82333; }
        .add-button { margin-top: 10px; padding: 5px 10px; background-color: #007BFF; color: #fff; border: none; cursor: pointer; border-radius: 3px; }
        .add-button:hover { background-color: #0056b3; }
    </style>
    <script>
        let assignmentIndex = <?php echo isset($pdks) ? count($pdks) + 1 : 2; ?>; // Начальное значение должно соответствовать уже существующему заданию

        function addAssignment() {
            const container = document.getElementById('assignments');
            const template = document.getElementById('assignment-template').innerHTML;
            const newAssignment = template.replace(/__ASSIGNMENT_INDEX__/g, assignmentIndex);
            const div = document.createElement('div');
            div.className = 'assignment-group';
            div.innerHTML = newAssignment;
            div.setAttribute('data-index', assignmentIndex);
            container.appendChild(div);
            
            // Добавляем атрибут required к видимым полям
            const selectElements = div.querySelectorAll('select, input[type="text"]');
            selectElements.forEach(el => {
                el.setAttribute('required', 'required');
            });
            assignmentIndex++;
        }

        function removeAssignment(el) {
            const assignment = el.closest('.assignment-group');
            assignment.parentNode.removeChild(assignment);
        }

        function addBrigade(el) {
            const assignmentGroup = el.closest('.assignment-group');
            const brigadeContainer = assignmentGroup.querySelector('.brigades');
            const currentAssignmentIndex = assignmentGroup.getAttribute('data-index');
            const currentBrigadeCount = brigadeContainer.querySelectorAll('.brigade-group').length + 1;
            const template = document.getElementById('brigade-template').innerHTML;
            const newBrigade = template.replace(/__ASSIGNMENT_INDEX__/g, currentAssignmentIndex)
                                       .replace(/__BRIGADE_INDEX__/g, currentBrigadeCount);
            const div = document.createElement('div');
            div.className = 'brigade-group';
            div.innerHTML = newBrigade;
            div.setAttribute('data-index', currentBrigadeCount);
            brigadeContainer.appendChild(div);
            
            // Добавляем атрибут required к видимым полям
            const selectElements = div.querySelectorAll('select, input[type="text"]');
            selectElements.forEach(el => {
                el.setAttribute('required', 'required');
            });
        }

        function removeBrigade(el) {
            const brigade = el.closest('.brigade-group');
            brigade.parentNode.removeChild(brigade);
        }

        function addStaff(el) {
            const staffContainer = el.parentNode;
            const select = staffContainer.querySelector('select').cloneNode(true);
            select.value = '';
            staffContainer.insertBefore(select, el);
        }
    </script>
</head>
<body>

<div class="container">
    <p>Вы вошли как <strong><?= htmlspecialchars($user['name']) ?></strong> (<?= htmlspecialchars($user['role']) ?>) | <a href="?action=logout">Выйти</a></p>

    <?php if (!empty($error)): ?>
        <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="message success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($user['role'] == 'Main Admin'): ?>
        <h1>Личный кабинет Главного Администратора</h1>
        <h2>Назначить ПДК</h2>
        <form method="post">
            <label>Дата ПДК:</label>
            <input type="date" name="pdk_date" required>

            <label>Тип ПДК:</label>
            <select name="pdk_type" required>
                <option value="">-- Выберите тип ПДК --</option>
                <option value="СНГ">СНГ</option>
                <option value="ВНГ">ВНГ</option>
            </select>

            <div id="assignments">
                <!-- Шаблон задания (скрытый) -->
                <div class="assignment-group" id="assignment-template" style="display:none;" data-index="__ASSIGNMENT_INDEX__">
                    <button type="button" class="remove-assignment" onclick="removeAssignment(this)">Удалить задание</button>

                    <label>Сотрудники для назначения (1 или 2):</label>
                    <div>
                        <select name="assignments[__ASSIGNMENT_INDEX__][staff_ids][]">
                            <option value="">-- Выберите сотрудника --</option>
                            <?php foreach ($staff as $member): ?>
                                <option value="<?= htmlspecialchars($member['id']) ?>"><?= htmlspecialchars($member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="add-button" onclick="addStaff(this)">Добавить сотрудника</button>
                    </div>

                    <div class="brigades">
                        <!-- Шаблон бригады (скрытый) -->
                        <div class="brigade-group brigade-template" style="display:none;" data-index="__BRIGADE_INDEX__">
                            <button type="button" class="remove-brigade" onclick="removeBrigade(this)">Удалить бригаду</button>
                            <label>Куст:</label>
                            <input type="text" name="assignments[__ASSIGNMENT_INDEX__][brigades][__BRIGADE_INDEX__][bush]">

                            <label>Бригада:</label>
                            <select name="assignments[__ASSIGNMENT_INDEX__][brigades][__BRIGADE_INDEX__][brigade_ids][]">
                                <option value="">-- Выберите бригаду --</option>
                                <?php foreach ($brigades as $brigade): ?>
                                    <option value="<?= htmlspecialchars($brigade['id']) ?>"><?= htmlspecialchars($brigade['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Конец шаблона бригады -->
                    </div>
                    <button type="button" class="add-button" onclick="addBrigade(this)">Добавить бригаду</button>
                </div>
                <!-- Конец шаблона задания -->

                <!-- Начальное задание -->
                <div class="assignment-group" data-index="1">
                    <button type="button" class="remove-assignment" onclick="removeAssignment(this)">Удалить задание</button>

                    <label>Сотрудники для назначения (1 или 2):</label>
                    <div>
                        <select name="assignments[1][staff_ids][]" required>
                            <option value="">-- Выберите сотрудника --</option>
                            <?php foreach ($staff as $member): ?>
                                <option value="<?= htmlspecialchars($member['id']) ?>"><?= htmlspecialchars($member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="add-button" onclick="addStaff(this)">Добавить сотрудника</button>
                    </div>

                    <div class="brigades">
                        <div class="brigade-group" data-index="1">
                            <button type="button" class="remove-brigade" onclick="removeBrigade(this)">Удалить бригаду</button>
                            <label>Куст:</label>
                            <input type="text" name="assignments[1][brigades][1][bush]" required>

                            <label>Бригада:</label>
                            <select name="assignments[1][brigades][1][brigade_ids][]" required>
                                <option value="">-- Выберите бригаду --</option>
                                <?php foreach ($brigades as $brigade): ?>
                                    <option value="<?= htmlspecialchars($brigade['id']) ?>"><?= htmlspecialchars($brigade['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="add-button" onclick="addBrigade(this)">Добавить бригаду</button>
                </div>
                <!-- Конец начального задания -->
            </div>
            <button type="button" class="add-assignment" onclick="addAssignment()">Добавить задание</button>
            <button type="submit" name="assign_pdk">Назначить ПДК</button>
        </form>

        <h2>Список назначенных ПДК</h2>
        <table>
            <tr>
                <th>№ ПДК</th>
                <th>Дата</th>
                <th>Тип</th>
                <th>Действие</th>
            </tr>
            <?php foreach ($pdks as $pdk): ?>
                <tr>
                    <td><?= htmlspecialchars($pdk['id']) ?></td>
                    <td><?= htmlspecialchars($pdk['date']) ?></td>
                    <td><?= htmlspecialchars($pdk['type']) ?></td>
                    <td><a href="index.php?pdk_id=<?= htmlspecialchars($pdk['id']) ?>">Просмотреть</a></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php
        if (isset($_GET['pdk_id'])) {
            $pdk_id = $_GET['pdk_id'];
            $stmt = $pdo->prepare('SELECT * FROM pdk WHERE id = ?');
            $stmt->execute([$pdk_id]);
            $pdk = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($pdk) {
                $stmt = $pdo->prepare('SELECT pd.*, GROUP_CONCAT(DISTINCT b.name SEPARATOR ", ") AS brigade_names FROM pdk_details pd
                                       JOIN pdk_brigades pb ON pd.id = pb.detail_id
                                       JOIN brigades b ON pb.brigade_id = b.id
                                       WHERE pd.pdk_id = ?
                                       GROUP BY pd.id');
                $stmt->execute([$pdk_id]);
                $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo "<h2>ПДК №" . htmlspecialchars($pdk['id']) . " от " . htmlspecialchars($pdk['date']) . "</h2>";
                foreach ($details as $detail) {
                    echo "<h3>Куст: " . htmlspecialchars($detail['bush']) . "</h3>";
                    echo "<p><strong>Бригады:</strong> " . htmlspecialchars($detail['brigade_names']) . "</p>";

                    $stmt = $pdo->prepare('SELECT a.*, u.name AS admin_name FROM acts a
                                           JOIN users u ON a.user_id = u.id
                                           WHERE a.detail_id = ?');
                    $stmt->execute([$detail['id']]);
                    $acts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($acts as $act) {
                        echo "<h4>Акт №" . htmlspecialchars($act['id']) . " от " . htmlspecialchars($act['date']) . " (Проверяющий: " . htmlspecialchars($act['admin_name']) . ")</h4>";
                        $violations = json_decode($act['violations'], true);
                        $comments = json_decode($act['comments'], true);
                        $photos = json_decode($act['photos'], true);

                        echo "<table>";
                        echo "<tr><th>Нарушение</th><th>Комментарий</th><th>Фото</th><th>Статус</th><th>Дата выполнения</th></tr>";
                        foreach ($violations as $violation_id) {
                            $stmt = $pdo->prepare('SELECT description FROM checklist_items WHERE id = ?');
                            $stmt->execute([$violation_id]);
                            $violation = $stmt->fetch(PDO::FETCH_ASSOC);

                            $stmt = $pdo->prepare('SELECT completion_date FROM violation_completions WHERE act_id = ? AND violation_id = ?');
                            $stmt->execute([$act['id'], $violation_id]);
                            $completion = $stmt->fetch(PDO::FETCH_ASSOC);

                            $photo = isset($photos[$violation_id]) && !empty($photos[$violation_id]) ? "<img src='uploads/" . htmlspecialchars($photos[$violation_id]) . "' width='100'>" : '—';
                            $status = $completion ? 'Выполнено' : 'Не выполнено';
                            $completion_date = $completion ? date('d.m.Y', strtotime($completion['completion_date'])) : '—';

                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($violation['description']) . "</td>";
                            echo "<td>" . htmlspecialchars($comments[$violation_id] ?? '') . "</td>";
                            echo "<td>" . $photo . "</td>";
                            echo "<td>" . $status . "</td>";
                            echo "<td>" . $completion_date . "</td>";
                            echo "</tr>";
                        }
                        echo "</table>";
                    }
                }
            } else {
                echo "<p>ПДК не найден.</p>";
            }
        }
        ?>

    <?php elseif ($user['role'] == 'Junior Admin'): ?>
        <h1>Личный кабинет Администратора ПДК</h1>
        <h2>Назначенные ПДК</h2>
        <?php if (!empty($assigned_pdks)): ?>
            <table>
                <tr>
                    <th>№ ПДК</th>
                    <th>Дата</th>
                    <th>Тип</th>
                    <th>Куст</th>
                    <th>Действие</th>
                </tr>
                <?php foreach ($assigned_pdks as $pdk): ?>
                    <tr>
                        <td><?= htmlspecialchars($pdk['pdk_id']) ?></td>
                        <td><?= htmlspecialchars($pdk['date']) ?></td>
                        <td><?= htmlspecialchars($pdk['type']) ?></td>
                        <td><?= htmlspecialchars($pdk['bush']) ?></td>
                        <td><a href="index.php?detail_id=<?= htmlspecialchars($pdk['id']) ?>">Перейти к ПДК</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>У вас нет назначенных ПДК.</p>
        <?php endif; ?>

        <?php
        if (isset($_GET['detail_id'])) {
            $detail_id = $_GET['detail_id'];

            $stmt = $pdo->prepare('SELECT pd.*, p.date, p.type FROM pdk_details pd
                                   JOIN pdk p ON pd.pdk_id = p.id
                                   WHERE pd.id = ?');
            $stmt->execute([$detail_id]);
            $detail = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($detail) {
                $stmt = $pdo->prepare('SELECT b.name FROM pdk_brigades pb
                                       JOIN brigades b ON pb.brigade_id = b.id
                                       WHERE pb.detail_id = ?');
                $stmt->execute([$detail_id]);
                $brigades = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $checklist = $pdo->query('SELECT id, description FROM checklist_items')->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <h2>ПДК №<?= htmlspecialchars($detail['pdk_id']) ?> от <?= htmlspecialchars($detail['date']) ?></h2>
                <p><strong>Куст:</strong> <?= htmlspecialchars($detail['bush']) ?></p>
                <p><strong>Бригады:</strong> <?= htmlspecialchars(implode(', ', $brigades)) ?></p>

                <h3>Чек-лист</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="detail_id" value="<?= htmlspecialchars($detail['id']) ?>">
                    <?php foreach ($checklist as $item): ?>
                        <div style="margin-bottom: 10px;">
                            <input type="checkbox" name="violations[]" value="<?= htmlspecialchars($item['id']) ?>"> <?= htmlspecialchars($item['description']) ?><br>
                            <label>Комментарий:</label><br>
                            <textarea name="comments[<?= htmlspecialchars($item['id']) ?>]" rows="2"></textarea><br>
                            <label>Фотоотчет (необязательно):</label><br>
                            <input type="file" name="photos[<?= htmlspecialchars($item['id']) ?>]"><br>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" name="submit_act">Составить Акт нарушений</button>
                </form>
                <?php
            } else {
                echo "<p>Деталь ПДК не найдена.</p>";
            }
        }
        ?>

    <?php elseif ($user['role'] == 'Master'): ?>
        <h1>Личный кабинет Мастера</h1>
        <h2>Акты нарушений вашей бригады</h2>
        <?php if (!empty($acts)): ?>
            <?php foreach ($acts as $act): ?>
                <div style="margin-bottom: 30px;">
                    <h3>Акт №<?= htmlspecialchars($act['id']) ?> от <?= htmlspecialchars($act['date']) ?></h3>
                    <p><strong>Провел ПДК:</strong> <?= htmlspecialchars($act['admin_name']) ?></p>
                    <?php
                    $violations = json_decode($act['violations'], true);
                    $comments = json_decode($act['comments'], true);
                    $photos = json_decode($act['photos'], true);

                    // Получение завершенных нарушений для текущего пользователя
                    $stmt = $pdo->prepare('SELECT violation_id, completion_date FROM violation_completions WHERE act_id = ? AND user_id = ?');
                    $stmt->execute([$act['id'], $user_id]);
                    $completions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    ?>

                    <h4>Нарушения:</h4>
                    <table>
                        <tr>
                            <th>Описание</th>
                            <th>Комментарий</th>
                            <th>Фото</th>
                            <th>Статус</th>
                            <th>Действие</th>
                        </tr>
                        <?php foreach ($violations as $violation_id): ?>
                            <?php
                            $stmt = $pdo->prepare('SELECT description FROM checklist_items WHERE id = ?');
                            $stmt->execute([$violation_id]);
                            $violation = $stmt->fetch(PDO::FETCH_ASSOC);

                            $is_completed = isset($completions[$violation_id]);
                            $completion_date = $is_completed ? date('d.m.Y', strtotime($completions[$violation_id])) : '';
                            $photo = isset($photos[$violation_id]) && !empty($photos[$violation_id]) ? "<img src='uploads/" . htmlspecialchars($photos[$violation_id]) . "' width='100'>" : '—';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($violation['description']) ?></td>
                                <td><?= htmlspecialchars($comments[$violation_id] ?? '') ?></td>
                                <td><?= $photo ?></td>
                                <td><?= $is_completed ? 'Выполнено' : 'Не выполнено' ?></td>
                                <td>
                                    <?php if (!$is_completed): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="act_id" value="<?= htmlspecialchars($act['id']) ?>">
                                            <input type="hidden" name="violation_id" value="<?= htmlspecialchars($violation_id) ?>">
                                            <label>Дата выполнения:</label>
                                            <input type="text" name="completion_date" placeholder="дд.мм.гггг" required pattern="\d{2}\.\d{2}\.\d{4}">
                                            <button type="submit" name="mark_violation">Отметить как выполненное</button>
                                        </form>
                                    <?php else: ?>
                                        <span><?= htmlspecialchars($completion_date) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Нет актов нарушений для вашей бригады.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>У вас нет доступа к этой странице.</p>
    <?php endif; ?>
</div>

<!-- Шаблоны, скрытые от пользователя -->
<div id="templates" style="display:none;">
    <!-- Шаблон бригады -->
    <div id="brigade-template">
        <button type="button" class="remove-brigade" onclick="removeBrigade(this)">Удалить бригаду</button>
        <label>Куст:</label>
        <input type="text" name="assignments[__ASSIGNMENT_INDEX__][brigades][__BRIGADE_INDEX__][bush]">

        <label>Бригада:</label>
        <select name="assignments[__ASSIGNMENT_INDEX__][brigades][__BRIGADE_INDEX__][brigade_ids][]">
            <option value="">-- Выберите бригаду --</option>
            <?php foreach ($brigades as $brigade): ?>
                <option value="<?= htmlspecialchars($brigade['id']) ?>"><?= htmlspecialchars($brigade['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

</body>
</html>
