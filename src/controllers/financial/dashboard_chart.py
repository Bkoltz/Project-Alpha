import mysql.connector

conn = mysql.connector.connect(
    host="localhost",
    user="your_username",
    password="your_password",
    database="your_database"
)
import json
from datetime import datetime, timedelta

def get_income_data():
    # Load settings
    with open("/../../../config/settings.json") as f:
        settings = json.load(f)
    months_to_show = settings["dashboard"].get("months_to_show", 6)

    # Date range
    end_date = datetime.now()
    start_date = end_date - timedelta(days=30*months_to_show)

    conn = mysql.connect("your_database.db")
    cursor = conn.cursor()

    query = """
        SELECT strftime('%Y-%m', invoiceDate) as month, SUM(total) as income
        FROM documents
        WHERE status = 'paid' AND invoiceDate BETWEEN ? AND ?
        GROUP BY strftime('%Y-%m', invoiceDate)
        ORDER BY month ASC
    """
    cursor.execute(query, (start_date.strftime("%Y-%m-%d"), end_date.strftime("%Y-%m-%d")))
    rows = cursor.fetchall()
    conn.close()

    # Format for frontend
    return [{"month": r[0], "income": r[1]} for r in rows]