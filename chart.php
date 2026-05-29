<?php
    ob_start();
    include 'functions.php';
    $chart_image = generateTodaysChart();
    ob_end_clean();

    header('Content-Type: image/png');
    echo $chart_image;
?>
