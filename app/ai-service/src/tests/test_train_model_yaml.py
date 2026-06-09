import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import train_model_yaml


class TrainModelYamlTest(unittest.TestCase):
    def test_main_creates_output_directory_before_training_saves_artifacts(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            output_path = Path(temp_dir) / "nested" / "model"

            def assert_output_dir_exists(df, output_path_value, *args):
                self.assertTrue(Path(output_path_value).is_dir())

            with patch.object(train_model_yaml, "load_data", return_value=object()):
                with patch.object(train_model_yaml, "train_model", side_effect=assert_output_dir_exists):
                    train_model_yaml.main(
                        "companies.csv",
                        "esrs.csv",
                        str(output_path),
                        0,
                        "RF",
                        "chain",
                        "group",
                        "none",
                    )


if __name__ == "__main__":
    unittest.main()
