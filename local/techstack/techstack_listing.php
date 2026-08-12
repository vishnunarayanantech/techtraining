<?php
require_once(__DIR__ . '/../../config.php');
require_login();

global $DB, $OUTPUT, $PAGE;

$PAGE->set_url(new moodle_url('/local/techstack/techstack_listing.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Tech Categories and Users');
$PAGE->set_heading('Tech Categories and Users');

//For DataTables
$PAGE->requires->js(new moodle_url('https://code.jquery.com/jquery-3.7.0.min.js'), true);
$PAGE->requires->js(new moodle_url('https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js'), true);
$PAGE->requires->css(new moodle_url('https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css'));
//For bootstrap icons
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css'));

echo $OUTPUT->header();

// URL to creation page
$createurl = new moodle_url('/local/techstack/techstack.php');

echo html_writer::start_div('mb-3'); // Add some margin below
echo html_writer::link(
    $createurl,
    'Create New Tech Category',
    ['class' => 'btn btn-primary'] // Bootstrap button class
);
echo html_writer::end_div();

// Step 1: Get all tech categories
$techcategories = $DB->get_records('techstack');
$courseMappings = $DB->get_records('mapcourses'); // Fetch mapping table

$categories = [];
foreach ($techcategories as $cat) {
    $categories[$cat->id] = [
        'id'   => $cat->id,
        'name' => $cat->tech_category,
        'users' => []
    ];

    // Step 2: Get user IDs from manager column
    if (!empty($cat->manager)) {
        $userids = explode(',', $cat->manager);

        // Step 3: Get user names from user IDs
        list($sqlin, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $users = $DB->get_records_select('user', "id $sqlin", $params);

        foreach ($users as $u) {
            $categories[$cat->id]['users'][] = fullname($u); // Firstname + Lastname
        }
    }
    
        // ---- Fetch Mapped Courses ----
        $mappednames = [];
        foreach ($courseMappings as $map) {
            if ($map->cat_id == $cat->id && !empty($map->mapped_courses)) {
    
                $courseids = explode(',', $map->mapped_courses);
    
                list($csql, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
                $courses = $DB->get_records_select('course', "id $csql", $cparams);
    
                foreach ($courses as $c) {
                    $mappednames[] = $c->fullname;
                }
            }
        }
    
        // Store names
        $categories[$cat->id]['mappedcourses'] = implode(', ', $mappednames);
}


?>


<table id="techstack_table" class="display">
    <thead>
    <tr>
        <th>Tech Category</th>
        <th>Users</th>
        <th>Edit</th>
        <th>Delete</th>
        <th>Map Courses</th>
        <th>Mapped Courses</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($categories as $category): ?>
        <tr>
            <td><?php echo s($category['name']); ?></td>
            <td><?php echo s(implode(', ', $category['users'])); ?></td>
            <td><a href="<?php echo new moodle_url('/local/techstack/edit_techstack.php', ['id' => $category['id']]);?>" title="Edit"><i class="bi bi-pencil"></i></a></td>
            <td><a href="<?php echo new moodle_url('/local/techstack/delete_techstack.php', ['id' => $category['id']]);?>" title="Delete"><i class="bi bi-trash"></i></a></td>
            <td><a href="<?php echo new moodle_url('/local/techstack/mapcourses.php', ['id' => $category['id']]);?>" title="Map Courses"><i class="bi bi-diagram-3"></i></a></td>
            <td><?php echo s($category['mappedcourses']); ?></td>


        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        $('#techstack_table').DataTable();
    });
</script>

<?php
echo $OUTPUT->footer();
?>
