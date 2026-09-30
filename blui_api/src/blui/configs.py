from pathlib import Path

from common.configs import CommonConfig


class BluiConfig(CommonConfig):
    knowledge_dir: Path = Path("data/blui")
    inquiry_url: str = "https://bluesoft.co.kr/inquiry.php"
    request_url: str = "https://bluesoft.co.kr/request.php"
    max_question_length: int = 1000
    max_user_messages: int = 30


config = BluiConfig()
