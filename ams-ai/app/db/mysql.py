import logging
import pymysql
import pymysql.cursors
from app.config import settings

logger = logging.getLogger("ams-ai.db")

def get_db_connection():
    """
    Establish a fresh, resilient MySQL connection with PyMySQL.
    """
    try:
        conn = pymysql.connect(
            host=settings.MYSQL_HOST,
            port=settings.MYSQL_PORT,
            database=settings.MYSQL_DATABASE,
            user=settings.MYSQL_USER,
            password=settings.MYSQL_PASSWORD,
            connect_timeout=10,
            autocommit=True,
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor
        )
        return conn
    except Exception as e:
        logger.error(f"Failed to establish MySQL connection to {settings.MYSQL_HOST}: {e}")
        raise e

def execute_query(query: str, params: tuple = (), fetch: str = "all", dict_cursor: bool = True):
    """
    Execute a parameterized SQL query safely with auto-retry on connection drops.
    
    Args:
        query: SQL query string.
        params: Parameters to substitute into the query.
        fetch: 'all', 'one', or 'none'.
        dict_cursor: If True, returns results as dictionaries instead of tuples.
    """
    max_retries = 2
    for attempt in range(max_retries):
        conn = None
        cursor = None
        try:
            conn = get_db_connection()
            cursor = conn.cursor(pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)
            
            # Normalize SQLite/ODBC style '?' placeholders to MySQL '%s' placeholders
            if "?" in query:
                query = query.replace("%", "%%").replace("?", "%s")
                
            cursor.execute(query, params)
            
            if fetch == "all":
                result = cursor.fetchall()
            elif fetch == "one":
                result = cursor.fetchone()
            else:
                conn.commit()
                result = cursor.lastrowid or cursor.rowcount
                
            return result
            
        except Exception as e:
            logger.warning(f"SQL execution attempt {attempt + 1}/{max_retries} failed: {e}")
            if attempt == max_retries - 1:
                logger.error(f"SQL execution permanent failure: {e} | Query: {query} | Params: {params}")
                raise e
        finally:
            if cursor:
                try:
                    cursor.close()
                except Exception:
                    pass
            if conn:
                try:
                    conn.close()
                except Exception:
                    pass
