    <option value="" selected="" disabled="">-- Select Name --</option>

<?php 
include('../include/db_config.php');
$adm = $_POST['adm'];
if($adm == "Reset") {
    $types = "";
} else {
   $types = " AND type='$adm'";  
}
echo "SELECT * FROM signup WHERE designation !='admin' AND status = 'Active' $types";
          $query1 = mysqli_query($con,"SELECT * FROM signup WHERE designation !='admin' AND status = 'Active' $types");

          while($fetch = mysqli_fetch_assoc($query1)){
             ?>
    <option <?php if($intern==$fetch['userId']){ echo "selected"; } ?> value="<?php echo $fetch['userId']; ?>"><?php echo $fetch['fullName']; ?></option>
                                                        
         <?php
            }
    ?>
