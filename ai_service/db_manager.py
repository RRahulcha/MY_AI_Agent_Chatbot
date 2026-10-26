import os
from datetime import datetime, date, timedelta
import mysql.connector
from dotenv import dotenv_values

class DatabaseManager:
    def __init__(self, env_path=None):
        if env_path is None:
            env_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".env"))
            if not os.path.exists(env_path):
                alt_env = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "env"))
                if os.path.exists(alt_env):
                    env_path = alt_env
        self.env_path = env_path
        self.config = dotenv_values(env_path)
        
        db_url = self.config.get('DATABASE_URL') or os.environ.get('DATABASE_URL')
        if db_url:
            from urllib.parse import urlparse, unquote
            parsed = urlparse(db_url)
            self.host = parsed.hostname or 'localhost'
            self.user = unquote(parsed.username) if parsed.username else 'root'
            self.password = unquote(parsed.password) if parsed.password else ''
            self.database = parsed.path.lstrip('/') if parsed.path else ''
            self.port = int(parsed.port or 3306)
        else:
            self.host = self.config.get('DB_HOST', os.environ.get('DB_HOST', '103.160.107.18'))
            self.user = self.config.get('DB_USER', os.environ.get('DB_USER', 'nmrmlatur_settribe_ams'))
            self.password = self.config.get('DB_PASS', os.environ.get('DB_PASS', 'SETTribe@ams1821'))
            self.database = self.config.get('DB_NAME', os.environ.get('DB_NAME', 'nmrmlatur_settribe_ams'))
            self.port = int(self.config.get('DB_PORT', os.environ.get('DB_PORT', 3306)))
    
    def get_connection(self):
        return mysql.connector.connect(
            host=self.host,
            user=self.user,
            password=self.password,
            database=self.database,
            port=self.port,
            ssl_disabled=True,
            connection_timeout=25
        )
    
    def get_active_employees(self):
        """Fetch active employees with their profile photos"""
        conn = self.get_connection()
        cursor = conn.cursor(dictionary=True)
        try:
            query = """
                SELECT userId, username, fullName, profilePhoto, designation, userRole, 
                       expectedPunchIn, expectedPunchOut, totalWorkingHours, status
                FROM signup 
                WHERE (status IS NULL OR LOWER(status) = 'active') 
                  AND profilePhoto IS NOT NULL 
                  AND profilePhoto != ''
                  AND LOWER(profilePhoto) NOT LIKE '%default%'
            """
            cursor.execute(query)
            employees = cursor.fetchall()
            return employees
        finally:
            cursor.close()
            conn.close()

    def get_today_attendance(self, user_id, conn=None):
        """Check today's attendance record for user"""
        today_str = date.today().strftime('%Y-%m-%d')
        own_conn = False
        if conn is None:
            conn = self.get_connection()
            own_conn = True

        cursor = conn.cursor(dictionary=True)
        try:
            query = """
                SELECT id, date, punchInTime, punchOutTime, status, manHours 
                FROM attendance 
                WHERE userId = %s AND date = %s 
                ORDER BY id DESC LIMIT 1
            """
            cursor.execute(query, (user_id, today_str))
            row = cursor.fetchone()
            
            if not row:
                return {"has_punched_in": False, "has_punched_out": False, "row": None}
            
            p_in = str(row['punchInTime']).strip() if row['punchInTime'] else ''
            p_out = str(row['punchOutTime']).strip() if row['punchOutTime'] else ''
            
            has_in = bool(p_in and p_in not in ('00:00:00', '00:00', 'None'))
            has_out = bool(p_out and p_out not in ('00:00:00', '00:00', 'None', 'null'))
            
            return {
                "has_punched_in": has_in,
                "has_punched_out": has_out,
                "row": row
            }
        finally:
            cursor.close()
            if own_conn:
                conn.close()

    def mark_attendance(self, user_id, employee_name, user_role="", expected_in="09:30:00", image_name="", latitude=0.0, longitude=0.0):
        """Automatically marks Punch In or Punch Out based on current status with GPS Location"""
        today_str = date.today().strftime('%Y-%m-%d')
        now_dt = datetime.now()
        time_str = now_dt.strftime('%H:%M:%S')
        datetime_str = now_dt.strftime('%Y-%m-%d %H:%M:%S')
        
        conn = self.get_connection()
        att_status = self.get_today_attendance(user_id, conn=conn)
        
        cursor = conn.cursor(dictionary=True)
        try:
            # Case 1: Not yet punched in -> Mark PUNCH IN
            if not att_status["has_punched_in"]:
                # Evaluate Late Mark (grace period: expectedPunchIn + 15 mins)
                late_mark = 'no'
                try:
                    exp_time = datetime.strptime(str(expected_in), '%H:%M:%S').time() if expected_in else datetime.strptime('09:30:00', '%H:%M:%S').time()
                    now_time = now_dt.time()
                    # 15 minutes grace
                    grace_time = (datetime.combine(date.today(), exp_time) + timedelta(minutes=15)).time()
                    if now_time > grace_time:
                        late_mark = 'yes'
                except Exception:
                    pass
                
                over_time = 'yes' if time_str < '09:00:00' else 'no'
                status_str = 'Present'
                
                insert_query = """
                    INSERT INTO attendance 
                    (date, dateTime, userId, punchInTime, punchInImage, punchInLatitude, punchInLongitude,
                     status, lateMark, intimated, overTime, punchOutStatus)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """
                cursor.execute(insert_query, (
                    today_str, datetime_str, user_id, time_str, image_name or 'live_ai_scan', 
                    str(latitude or '0.0'), str(longitude or '0.0'),
                    status_str, late_mark, 'no', over_time, 'Normal'
                ))
                conn.commit()
                att_id = cursor.lastrowid
                
                return {
                    "success": True,
                    "action": "punch_in",
                    "attendance_id": att_id,
                    "user_id": user_id,
                    "user_name": employee_name,
                    "time": time_str,
                    "date": today_str,
                    "latitude": latitude,
                    "longitude": longitude,
                    "message": f"Punch In Marked Successfully for {employee_name} at {time_str}"
                }
            
            # Case 2: Punched In, but not yet punched out -> Mark PUNCH OUT
            elif att_status["has_punched_in"] and not att_status["has_punched_out"]:
                row = att_status["row"]
                att_id = row['id']
                p_in_time_str = str(row['punchInTime'])
                
                # Calculate working hours
                try:
                    p_in_dt = datetime.strptime(f"{today_str} {p_in_time_str}", "%Y-%m-%d %H:%M:%S")
                    diff = now_dt - p_in_dt
                    total_seconds = int(diff.total_seconds())
                    hours = total_seconds // 3600
                    minutes = (total_seconds % 3600) // 60
                    seconds = total_seconds % 60
                    man_hours = f"{hours:02d}:{minutes:02d}:{seconds:02d}"
                except Exception:
                    man_hours = "00:00:00"
                
                update_query = """
                    UPDATE attendance 
                    SET punchOutTime = %s, punchOutImage = %s, punchOutLatitude = %s, punchOutLongitude = %s,
                        manHours = %s, actualManHour = %s, punchOutStatus = 'Normal'
                    WHERE id = %s
                """
                cursor.execute(update_query, (
                    time_str, image_name or 'live_ai_scan', str(latitude or '0.0'), str(longitude or '0.0'),
                    man_hours, man_hours, att_id
                ))
                conn.commit()
                
                return {
                    "success": True,
                    "action": "punch_out",
                    "attendance_id": att_id,
                    "user_id": user_id,
                    "user_name": employee_name,
                    "time": time_str,
                    "date": today_str,
                    "man_hours": man_hours,
                    "message": f"Punch Out Marked Successfully for {employee_name} at {time_str} (Working Hours: {man_hours})"
                }
            
            # Case 3: Already completed both for today
            else:
                return {
                    "success": True,
                    "action": "already_completed",
                    "user_id": user_id,
                    "user_name": employee_name,
                    "time": time_str,
                    "message": f"Attendance already completed for today for {employee_name}."
                }
                
        finally:
            cursor.close()
            conn.close()

    def update_user_profile_photo(self, user_id, photo_filename):
        """Update employee profile photo in MySQL signup table"""
        conn = self.get_connection()
        cursor = conn.cursor(dictionary=True)
        try:
            query = "UPDATE signup SET profilePhoto = %s WHERE userId = %s"
            cursor.execute(query, (photo_filename, str(user_id)))
            conn.commit()
            return True
        except Exception as e:
            print(f"[!] Error updating profilePhoto for {user_id}: {e}")
            return False
        finally:
            cursor.close()
            conn.close()

    def get_employee_by_id(self, user_id):
        """Fetch employee details by userId or username"""
        conn = self.get_connection()
        cursor = conn.cursor(dictionary=True)
        try:
            query = """
                SELECT userId, username, fullName, profilePhoto, designation, userRole, 
                       expectedPunchIn, expectedPunchOut, status
                FROM signup 
                WHERE userId = %s OR username = %s 
                LIMIT 1
            """
            cursor.execute(query, (str(user_id), str(user_id)))
            return cursor.fetchone()
        finally:
            cursor.close()
            conn.close()
