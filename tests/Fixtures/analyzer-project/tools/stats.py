class Stats:
    def __init__(self):
        self.values = []

    def add(self, value):
        self.values.append(value)


def average(values):
    return sum(values) / len(values)
