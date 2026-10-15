import unittest

from services.materiality_recommender import build_propensity, recommend


def _esrs(rows):
    return [{"file": f, **{k: v for k, v in cells.items()}} for f, cells in rows]


class RecommenderTests(unittest.TestCase):
    def setUp(self):
        # 3 companies; topics t_a, t_b. codes via a simple map.
        self.cols = ["t_a", "t_b"]
        self.esrs = [
            {"file": "f1", "t_a": "1", "t_b": "0"},
            {"file": "f2", "t_a": "1", "t_b": "0"},
            {"file": "f3", "t_a": "0", "t_b": "1"},
            {"file": "f4", "t_a": "1", "t_b": "1"},
            {"file": "f5", "t_a": "1", "t_b": "0"},
        ]
        self.mask = {f["file"]: {"t_a": "1", "t_b": "1"} for f in self.esrs}
        self.codes = {"f1": {"C"}, "f2": {"C"}, "f3": {"D"}, "f4": {"C"}, "f5": {"C"}}
        self.labels = {"t_a": "Topic A", "t_b": "Topic B"}
        self.prop = build_propensity(self.esrs, self.mask,
                                     lambda f: self.codes.get(f, set()), min_support=1)

    def test_propensity_counts_per_code(self):
        # code C: 4 companies, t_a material in 4/4 -> 1.0 ; t_b 1/4 -> 0.25
        self.assertEqual(self.prop["C"]["t_a"]["pct"], 1.0)
        self.assertEqual(self.prop["C"]["t_b"]["pct"], 0.25)

    def test_recommend_ranks_and_thresholds(self):
        recs = recommend({"C"}, self.prop, self.labels, threshold=0.5)
        keys = [r.esrs_key for r in recs]
        self.assertEqual(keys, ["t_a"])  # only t_a clears 0.5 for code C

    def test_multicode_uses_max_with_provenance(self):
        # a company in both C and D: t_b is 0.25 in C but 1.0 in D -> max 1.0, both drivers
        recs = recommend({"C", "D"}, self.prop, self.labels, threshold=0.3)
        tb = next(r for r in recs if r.esrs_key == "t_b")
        self.assertEqual(tb.score, 1.0)
        self.assertEqual(tb.drivers[0]["code"], "D")  # strongest driver first

    def test_no_code_falls_back_low_confidence(self):
        recs = recommend({"Z"}, self.prop, self.labels, threshold=0.3)
        self.assertTrue(all(r.low_confidence for r in recs))
        self.assertTrue(all(r.drivers[0]["code"] == "(base rate)" for r in recs))

    def test_min_support_drops_thin_codes(self):
        # with min_support=2, code D (only 1 company) is dropped; code C (4) kept
        prop = build_propensity(self.esrs, self.mask,
                                lambda f: self.codes.get(f, set()), min_support=2)
        self.assertNotIn("D", prop)
        self.assertIn("C", prop)


if __name__ == "__main__":
    unittest.main()
