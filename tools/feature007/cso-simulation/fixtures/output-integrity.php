<?php
for($i=0;$i<1500;$i++){
    $line=sprintf('%04d|العربية|🙂|fixture|%s|%s',$i,str_repeat('x',23),chr(0))."\n";
    echo $line;fwrite(STDERR,'stderr:'.$line);
    $overwrite=str_repeat('W',131072);unset($overwrite);
}
echo 'tail🙂';fwrite(STDERR,'stderr-tail🙂');
