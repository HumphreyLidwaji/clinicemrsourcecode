<?php
/*
 * Pagination Body/Footer — Safe Version
 * Displays page number buttons
 * Expects: $num_rows[0], $user_config_records_per_page
 */

// ----------------------------
// 1. Validate input variables
// ----------------------------
$total_found_rows = isset($num_rows[0]) ? intval($num_rows[0]) : 0;

// Default per-page fallback
if (empty($user_config_records_per_page) || $user_config_records_per_page <= 0) {
    $user_config_records_per_page = 10;
}

$per_page = $user_config_records_per_page;

// ----------------------------
// 2. Calculate Pagination Values
// ----------------------------
$total_pages = ($total_found_rows > 0)
    ? ceil($total_found_rows / $per_page)
    : 1;

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$start = ($page - 1) * $per_page + 1;
$end   = min($page * $per_page, $total_found_rows);

// ----------------------------
// 3. Page block split logic (clean)
// ----------------------------
if ($total_pages <= 100)         $pages_split = 10;
elseif ($total_pages <= 1000)    $pages_split = 100;
elseif ($total_pages <= 10000)   $pages_split = 1000;
else                             $pages_split = 5000; // catch-all safety

// ----------------------------
// 4. No results? Display and stop
// ----------------------------
if ($total_found_rows == 0) {
    echo "<center class='my-3'>
            <i class='far fa-fw fa-6x fa-meh-rolling-eyes text-secondary'></i>
            <h3 class='text-secondary mt-3'>No Results</h3>
          </center>";
    return;
}

// ----------------------------
// 5. Build safe GET string (no duplicate page parameter)
// ----------------------------
$get_copy = $_GET;
unset($get_copy['page']);
$url_qs = http_build_query($get_copy);

?>
<div class="card-footer pb-0 pt-3">
    <div class="row">

        <!-- Records per page selector -->
        <div class="col-sm">
            <form action="/clinic/post.php" method="post">
                <div class="form-group">
                    <select onchange="this.form.submit()" class="form-control select2 col-12 col-sm-3" name="change_records_per_page">
                        <?php
                        foreach ([5,10,20,50,100,500] as $option) {
                            $sel = ($per_page == $option) ? "selected" : "";
                            echo "<option $sel>$option</option>";
                        }
                        ?>
                    </select>
                </div>
            </form>
        </div>

        <!-- Summary text -->
        <div class="col-sm">
            <p class="text-center">
                Showing <strong><?= $start ?></strong>
                to <strong><?= $end ?></strong>
                of <strong><?= $total_found_rows ?></strong> records
            </p>
        </div>

        <!-- Pagination buttons -->
        <div class="col-sm">
            <ul class="pagination justify-content-sm-end">

                <?php
                // Previous button
                $prev_page = $page - 1;
                $prev_disabled = ($page <= 1) ? "disabled" : "";
                echo "<li class='page-item $prev_disabled'>
                        <a class='page-link' href='?$url_qs&page=$prev_page'>Prev</a>
                      </li>";

                // Main page loop
                for ($i = 1; $i <= $total_pages; $i++) {

                    $show = (
                        $i == 1 ||
                        $i == $total_pages ||
                        abs($i - $page) <= 2 ||
                        ($i % $pages_split == 0) ||
                        ($page <= 3 && $i <= 6) ||
                        ($page >= $total_pages - 3 && $i >= $total_pages - 6)
                    );

                    if (!$show) continue;

                    $active = ($i == $page) ? "active" : "";
                    echo "<li class='page-item $active'>
                            <a class='page-link' href='?$url_qs&page=$i'>$i</a>
                          </li>";
                }

                // Next button
                $next_page = $page + 1;
                $next_disabled = ($page >= $total_pages) ? "disabled" : "";
                echo "<li class='page-item $next_disabled'>
                        <a class='page-link' href='?$url_qs&page=$next_page'>Next</a>
                      </li>";
                ?>

            </ul>
        </div>

    </div>
</div>
