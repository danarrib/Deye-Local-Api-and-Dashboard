<?php
    // Include functions.php
    include 'functions.php';

    $chart_image = generateTodaysChart();
    // output the image
    header('Content-Type: image/png');
    echo $chart_image;

?>
