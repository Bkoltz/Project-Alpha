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

    cursor = conn.cursor()

    query = """
        SELECT DATE_FORMAT(document_date, '%Y-%m') as month, SUM(total) as income
        FROM invoices
        WHERE status = 'paid' AND document_date BETWEEN %s AND %s
        GROUP BY DATE_FORMAT(document_date, '%Y-%m')
        ORDER BY month ASC
    """
    cursor.execute(query, (start_date.strftime("%Y-%m-%d"), end_date.strftime("%Y-%m-%d")))
    rows = cursor.fetchall()

    # Format for frontend
    return [{"month": r[0], "income": r[1]} for r in rows]
