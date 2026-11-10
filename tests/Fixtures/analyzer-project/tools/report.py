import json

from tools.stats import average


def build_report(prices):
    return json.dumps({"average": average(prices)})
