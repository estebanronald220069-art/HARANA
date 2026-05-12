<?php 
echo"=============Squareroot==================<br>";
echo "<br>";
$num = 25;
echo sqrt($num); 
echo "<br>";
echo"=============Ascending==================<br>";
echo "<br>";
echo "<br>";
 $arr = array(7, 2, 5, 4, 9);
  sort($arr);
  foreach ($arr as $Arr)
    {echo $Arr . " ";}
    echo "<br>";
echo"=============descending==================<br>";
echo "<br>";
    $s = array (4 , 3 , 7 ,9 ,2 ,8 ,1);
rsort($s);
foreach($s as $value){
 echo $value;
} echo "<br>";
echo"=============Ascending no foreach==================<br>";
echo "<br>";
$arr = [7, 2, 5, 4, 9];
sort($arr);
print_r($arr); echo "<br>";
echo "<br>";
echo"=============Ascending==================<br>";
echo "<br>";
$arr = ["a"=>7, "b"=>2, "c"=>5, "d"=>4, "e"=>9];
asort($arr);
print_r($arr);
?>