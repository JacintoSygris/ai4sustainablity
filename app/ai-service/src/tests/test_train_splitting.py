import unittest

import pandas as pd

from train.splitting import GroupShuffleSplitter


class TrainSplittingTest(unittest.TestCase):
    def test_group_shuffle_splitter_keeps_same_company_out_of_both_sides(self):
        splitter = GroupShuffleSplitter(test_size=0.4, random_state=7)
        x = pd.DataFrame({"feature": range(8)})
        y = pd.DataFrame({"esrs_e1": [1, 0, 1, 0, 1, 0, 1, 0]})
        groups = pd.Series(
            [
                "Acme",
                "Acme",
                "Beta",
                "Beta",
                "Gamma",
                "Gamma",
                "Delta",
                "Delta",
            ]
        )

        x_train, x_test, y_train, y_test = splitter.split(x, y, groups=groups)

        self.assertEqual(len(x_train), len(y_train))
        self.assertEqual(len(x_test), len(y_test))
        train_groups = set(groups.loc[x_train.index])
        test_groups = set(groups.loc[x_test.index])
        self.assertEqual(train_groups & test_groups, set())

    def test_group_shuffle_splitter_requires_groups(self):
        splitter = GroupShuffleSplitter()

        with self.assertRaisesRegex(ValueError, "groups"):
            splitter.split(
                pd.DataFrame({"feature": [1, 2]}),
                pd.DataFrame({"esrs_e1": [1, 0]}),
            )


if __name__ == "__main__":
    unittest.main()
